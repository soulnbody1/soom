<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SellerDepositDisposition;
use App\DTO\Auction\SellerDepositDispositionDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;

final class SellerDepositDispositionResolver
{
    public function __construct(
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
    ) {}

    public function resolve(
        Auction $auction,
        string $trigger,
        ?AuctionDeposit $deposit,
        string $actorType = 'system',
        string $reason = '',
        array $context = [],
    ): SellerDepositDispositionDTO {
        if ((int) $auction->seller_deposit_amount_minor <= 0) {
            return new SellerDepositDispositionDTO(
                SellerDepositDisposition::NoAction,
                'zero_required',
                $trigger,
                'seller deposit is not required'
            );
        }

        // No obligation row means there is nothing to dispose of. This is the case for
        // auctions that were never approved: the seller deposit obligation and the
        // configuration snapshot are both created at approval, so reading the snapshot
        // here would fail for a draft or pending-review auction.
        if ($deposit === null) {
            return new SellerDepositDispositionDTO(
                SellerDepositDisposition::NoAction,
                'no_obligation',
                $trigger,
                'seller deposit obligation does not exist'
            );
        }

        $policyKey = $this->policyKey($auction, $trigger, $actorType, $reason, $context);
        $configured = $context['seller_deposit_disposition'] ?? $this->configuredDisposition($auction, $policyKey);
        $disposition = $configured
            ? SellerDepositDisposition::from((string) $configured)
            : $this->defaultDisposition($policyKey);

        $forfeitAmount = $disposition === SellerDepositDisposition::PartialForfeit
            ? $this->partialForfeitAmount($auction, $policyKey, $context)
            : 0;

        if ($disposition === SellerDepositDisposition::PartialForfeit && $forfeitAmount <= 0) {
            $disposition = SellerDepositDisposition::ManualReview;
        }

        return new SellerDepositDispositionDTO(
            $disposition,
            $policyKey,
            $trigger,
            trim($reason) !== '' ? trim($reason) : $policyKey,
            $forfeitAmount,
        );
    }

    private function policyKey(Auction $auction, string $trigger, string $actorType, string $reason, array $context): string
    {
        if (isset($context['policy_key'])) {
            return (string) $context['policy_key'];
        }

        return match ($trigger) {
            'unsold' => 'unsold',
            'completed' => 'completed',
            'winner_default' => 'winner_default',
            'seller_breach' => 'seller_breach',
            'dispute_resolution' => 'dispute_'.$this->normalized((string) ($context['resolution'] ?? 'manual_review')),
            'cancellation' => $this->cancellationPolicyKey($auction, $actorType, $reason, $context),
            default => $trigger,
        };
    }

    private function cancellationPolicyKey(Auction $auction, string $actorType, string $reason, array $context): string
    {
        $fault = $this->fault($reason, $context);

        if ($actorType === 'user' && (int) ($context['actor_id'] ?? 0) === (int) $auction->seller_id) {
            return $this->started($auction, $context)
                ? 'seller_cancellation_after_start'
                : 'seller_cancellation_before_start';
        }

        if ($actorType === 'system') {
            return match ($fault) {
                'seller_fault' => 'system_cancellation_seller_fault',
                'platform_fault' => 'system_cancellation_platform_fault',
                default => 'system_cancellation_neutral',
            };
        }

        return match ($fault) {
            'platform_fault' => 'admin_cancellation_platform_fault',
            'seller_fault' => 'admin_cancellation_seller_fault',
            'fraud_or_compliance' => 'admin_cancellation_fraud_or_compliance',
            default => 'admin_cancellation_neutral',
        };
    }

    private function fault(string $reason, array $context): string
    {
        if (isset($context['fault'])) {
            return (string) $context['fault'];
        }

        $reason = strtolower($reason);

        if (str_contains($reason, 'platform_fault') || str_contains($reason, 'platform fault')) {
            return 'platform_fault';
        }

        if (str_contains($reason, 'seller_fault') || str_contains($reason, 'seller fault') || str_contains($reason, 'seller_breach')) {
            return 'seller_fault';
        }

        if (str_contains($reason, 'fraud') || str_contains($reason, 'compliance') || str_contains($reason, 'legal')) {
            return 'fraud_or_compliance';
        }

        return 'neutral';
    }

    private function started(Auction $auction, array $context): bool
    {
        $status = $context['auction_status_before'] ?? $auction->status;
        $status = $status instanceof AuctionStatus ? $status : AuctionStatus::from((string) $status);

        return in_array($status, [
            AuctionStatus::Live,
            AuctionStatus::Ended,
            AuctionStatus::SettlementPending,
            AuctionStatus::PaymentPending,
            AuctionStatus::HandoverPending,
            AuctionStatus::Disputed,
        ], true);
    }

    private function configuredDisposition(Auction $auction, string $policyKey): ?string
    {
        $snapshot = $this->snapshotReader->forAuction($auction);

        return $snapshot->sellerDepositDisposition($policyKey);
    }

    private function defaultDisposition(string $policyKey): SellerDepositDisposition
    {
        return match ($policyKey) {
            'unsold',
            'completed',
            'seller_cancellation_before_start',
            'admin_cancellation_platform_fault',
            'admin_cancellation_neutral',
            'system_cancellation_platform_fault',
            'system_cancellation_neutral',
            'dispute_complete' => SellerDepositDisposition::Refund,
            'seller_breach',
            'admin_cancellation_seller_fault',
            'system_cancellation_seller_fault' => SellerDepositDisposition::Forfeit,
            'winner_default',
            'dispute_resume_handover' => SellerDepositDisposition::KeepHeld,
            default => SellerDepositDisposition::ManualReview,
        };
    }

    private function partialForfeitAmount(Auction $auction, string $policyKey, array $context): int
    {
        $snapshot = $this->snapshotReader->forAuction($auction);

        return (int) (
            $context['forfeit_amount_minor']
            ?? $snapshot->sellerDepositForfeitAmount($policyKey)
        );
    }

    private function normalized(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
