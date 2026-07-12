<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\SellerDepositDisposition;
use App\DTO\Auction\SellerDepositDispositionDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\DepositRefundAllocation;
use App\Services\Auction\Support\FinancialObligationKey;
use App\Services\Auction\Support\SellerDepositDispositionResolver;
use Illuminate\Support\Carbon;

final class ResolveSellerDepositDispositionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionRefundRepository $refunds,
        private readonly AuctionSettlementRepository $settlements,
        private readonly SellerDepositDispositionResolver $resolver,
        private readonly RefundAuctionDepositAction $refundDeposit,
    ) {}

    public function execute(
        Auction $auction,
        string $trigger,
        ?int $actorId = null,
        string $actorType = 'system',
        string $reason = '',
        array $context = [],
    ): SellerDepositDispositionDTO {
        return $this->transaction->run(function () use ($auction, $trigger, $actorId, $actorType, $reason, $context): SellerDepositDispositionDTO {
            $auction = $this->auctions->lockForStateChange($auction->id)->loadMissing('configurationVersion');
            $deposit = $this->deposits->lockSellerDepositForAuction($auction->id);
            $decision = $this->resolver->resolve($auction, $trigger, $deposit, $actorType, $reason, [
                ...$context,
                'actor_id' => $actorId,
            ]);

            if ($deposit === null) {
                return $decision;
            }

            return match ($decision->disposition) {
                SellerDepositDisposition::Refund => $this->planRefund($auction, $deposit, $decision, $actorId, $actorType),
                SellerDepositDisposition::Forfeit => $this->forfeit($auction, $deposit, $decision, $actorId, $actorType),
                SellerDepositDisposition::PartialForfeit => $this->partialForfeit($auction, $deposit, $decision, $actorId, $actorType),
                SellerDepositDisposition::KeepHeld => $this->keepHeld($auction, $deposit, $decision, $actorId, $actorType),
                SellerDepositDisposition::ManualReview => $this->manualReview($auction, $deposit, $decision, $actorId, $actorType),
                SellerDepositDisposition::CloseWithoutRefund => $this->closeWithoutRefund($auction, $deposit, $decision, $actorId, $actorType),
                SellerDepositDisposition::NoAction => $decision,
            };
        });
    }

    private function planRefund(
        Auction $auction,
        AuctionDeposit $deposit,
        SellerDepositDispositionDTO $decision,
        ?int $actorId,
        string $actorType,
    ): SellerDepositDispositionDTO {
        $payment = $this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forDeposit($deposit));

        if (! $payment) {
            return $this->closeWithoutRefund($auction, $deposit, $decision, $actorId, $actorType);
        }

        $allocation = DepositRefundAllocation::calculateRefundableDepositAmount(
            $deposit,
            (int) $payment->amount_minor,
            $this->refunds->lockActiveOrSucceededForDeposit($deposit->id),
            $this->settlements->lockForDepositRefund($deposit)
        );

        if ($allocation->isEmpty()) {
            return $decision;
        }

        $refund = $this->refundDeposit->execute($deposit, "seller_deposit_{$decision->trigger}: {$decision->reason}", $actorId);

        if ($refund->wasRecentlyCreated) {
            $this->audit->log('auction.seller_deposit_refund_planned', $auction, $actorId, $actorType, [
                'deposit_public_id' => $deposit->public_id,
                'refund_public_id' => $refund->public_id,
                'source_payment_transaction_id' => $payment->id,
                'refund_amount' => $refund->amount_minor,
                ...$decision->metadata(),
            ]);
            $this->audit->outbox('auction.seller_deposit_refund_planned', $auction, [
                'deposit_public_id' => $deposit->public_id,
                'refund_public_id' => $refund->public_id,
                'amount_minor' => $refund->amount_minor,
                ...$decision->metadata(),
            ]);
        }

        return $decision;
    }

    private function forfeit(
        Auction $auction,
        AuctionDeposit $deposit,
        SellerDepositDispositionDTO $decision,
        ?int $actorId,
        string $actorType,
    ): SellerDepositDispositionDTO {
        $payment = $this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forDeposit($deposit));

        if (! $payment) {
            return $this->closeWithoutRefund($auction, $deposit, $decision, $actorId, $actorType);
        }

        $amount = $this->availableForfeitableAmount($deposit, (int) $payment->amount_minor);
        if ($amount <= 0) {
            return $decision;
        }

        $this->applyForfeiture($auction, $deposit, $decision, $amount, $actorId, $actorType, 'auction.seller_deposit_forfeited');

        return $decision;
    }

    private function partialForfeit(
        Auction $auction,
        AuctionDeposit $deposit,
        SellerDepositDispositionDTO $decision,
        ?int $actorId,
        string $actorType,
    ): SellerDepositDispositionDTO {
        $payment = $this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forDeposit($deposit));

        if (! $payment) {
            return $this->closeWithoutRefund($auction, $deposit, $decision, $actorId, $actorType);
        }

        $targetForfeited = min((int) $payment->amount_minor, $decision->forfeitAmountMinor);
        $remainingForfeit = max(0, $targetForfeited - (int) $deposit->forfeited_amount_minor);
        if ($remainingForfeit > 0) {
            $this->applyForfeiture($auction, $deposit, $decision, $remainingForfeit, $actorId, $actorType, 'auction.seller_deposit_partially_forfeited');
        }

        return $this->planRefund($auction, $deposit->refresh(), $decision, $actorId, $actorType);
    }

    private function keepHeld(
        Auction $auction,
        AuctionDeposit $deposit,
        SellerDepositDispositionDTO $decision,
        ?int $actorId,
        string $actorType,
    ): SellerDepositDispositionDTO {
        if ($deposit->status !== AuctionDepositStatus::Held) {
            return $decision;
        }

        $metadata = $decision->metadata();
        if ($deposit->hold_reason === 'seller_deposit_keep_held' && $deposit->hold_metadata === $metadata) {
            return $decision;
        }

        $deposit->forceFill([
            'hold_reason' => 'seller_deposit_keep_held',
            'hold_expires_at' => null,
            'hold_metadata' => $metadata,
        ]);
        $this->deposits->save($deposit);

        $this->audit->log('auction.seller_deposit_held', $auction, $actorId, $actorType, [
            'deposit_public_id' => $deposit->public_id,
            ...$metadata,
        ]);

        return $decision;
    }

    private function manualReview(
        Auction $auction,
        AuctionDeposit $deposit,
        SellerDepositDispositionDTO $decision,
        ?int $actorId,
        string $actorType,
    ): SellerDepositDispositionDTO {
        if ($deposit->hold_reason === 'seller_deposit_manual_review' && $deposit->hold_metadata === $decision->metadata()) {
            return $decision;
        }

        $deposit->forceFill([
            'hold_reason' => 'seller_deposit_manual_review',
            'hold_expires_at' => null,
            'hold_metadata' => $decision->metadata(),
        ]);
        $this->deposits->save($deposit);

        $this->audit->log('auction.seller_deposit_manual_review_required', $auction, $actorId, $actorType, [
            'deposit_public_id' => $deposit->public_id,
            ...$decision->metadata(),
        ]);
        $this->audit->outbox('auction.seller_deposit_manual_review', $auction, [
            'deposit_public_id' => $deposit->public_id,
            ...$decision->metadata(),
        ]);

        return $decision;
    }

    private function closeWithoutRefund(
        Auction $auction,
        AuctionDeposit $deposit,
        SellerDepositDispositionDTO $decision,
        ?int $actorId,
        string $actorType,
    ): SellerDepositDispositionDTO {
        if (
            $deposit->status === AuctionDepositStatus::Rejected
            && $deposit->released_at !== null
            && $deposit->hold_reason === 'seller_deposit_closed_without_payment'
        ) {
            return $decision;
        }

        $deposit->forceFill([
            'status' => AuctionDepositStatus::Rejected,
            'held_amount_minor' => 0,
            'applied_amount_minor' => 0,
            'hold_reason' => 'seller_deposit_closed_without_payment',
            'hold_expires_at' => null,
            'hold_metadata' => $decision->metadata(),
            'released_at' => Carbon::now(),
        ]);
        $this->deposits->save($deposit);

        $this->audit->log('auction.seller_deposit_closed_without_payment', $auction, $actorId, $actorType, [
            'deposit_public_id' => $deposit->public_id,
            ...$decision->metadata(),
        ]);

        return new SellerDepositDispositionDTO(
            SellerDepositDisposition::CloseWithoutRefund,
            $decision->policyKey,
            $decision->trigger,
            $decision->reason,
            $decision->forfeitAmountMinor,
        );
    }

    private function applyForfeiture(
        Auction $auction,
        AuctionDeposit $deposit,
        SellerDepositDispositionDTO $decision,
        int $amount,
        ?int $actorId,
        string $actorType,
        string $eventType,
    ): void {
        $amount = min($amount, max(0, (int) $deposit->held_amount_minor));
        if ($amount <= 0) {
            return;
        }

        $remainingHeld = (int) $deposit->held_amount_minor - $amount;
        $deposit->forceFill([
            'status' => $remainingHeld === 0 ? AuctionDepositStatus::Forfeited : AuctionDepositStatus::Held,
            'held_amount_minor' => $remainingHeld,
            'forfeited_amount_minor' => (int) $deposit->forfeited_amount_minor + $amount,
            'hold_reason' => $remainingHeld > 0 ? $deposit->hold_reason : null,
            'hold_expires_at' => $remainingHeld > 0 ? $deposit->hold_expires_at : null,
            'hold_metadata' => $remainingHeld > 0 ? $deposit->hold_metadata : null,
            'released_at' => $remainingHeld === 0 ? Carbon::now() : $deposit->released_at,
        ]);
        $this->deposits->save($deposit);

        $this->audit->log($eventType, $auction, $actorId, $actorType, [
            'deposit_public_id' => $deposit->public_id,
            'forfeited_amount' => $amount,
            'remaining_held_amount' => $remainingHeld,
            ...$decision->metadata(),
        ]);
        $this->audit->outbox($eventType, $auction, [
            'deposit_public_id' => $deposit->public_id,
            'forfeited_amount' => $amount,
            'remaining_held_amount' => $remainingHeld,
            ...$decision->metadata(),
        ]);
    }

    private function availableForfeitableAmount(AuctionDeposit $deposit, int $capturedAmountMinor): int
    {
        $reservedRefunds = $this->refunds->lockActiveOrSucceededForDeposit($deposit->id);
        $reservedHeld = (int) $reservedRefunds
            ->filter(fn ($refund): bool => in_array($refund->status->value, ['pending', 'processing'], true))
            ->sum(fn ($refund): int => (int) ($refund->held_refund_amount_minor ?? 0));

        return min(
            max(0, (int) $deposit->held_amount_minor - $reservedHeld),
            max(0, $capturedAmountMinor - (int) $deposit->refunded_amount_minor - (int) $deposit->forfeited_amount_minor - $reservedHeld),
        );
    }
}
