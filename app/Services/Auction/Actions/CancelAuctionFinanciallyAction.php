<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionCancellationTrigger;
use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SellerDepositDisposition;
use App\Domain\Auction\Exceptions\AuctionException;
use App\DTO\Auction\AuctionCancellationContextDTO;
use App\DTO\Auction\AuctionCancellationPlanDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSettlement;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\FinancialObligationKey;
use App\Services\Auction\Support\SellerDepositDispositionResolver;
use Illuminate\Support\Carbon;

final class CancelAuctionFinanciallyAction
{
    private const CANCELLABLE_STATUSES = [
        AuctionStatus::Draft,
        AuctionStatus::PendingReview,
        AuctionStatus::AwaitingSellerDeposit,
        AuctionStatus::Scheduled,
        AuctionStatus::Live,
        AuctionStatus::Ended,
        AuctionStatus::SettlementPending,
        AuctionStatus::PaymentPending,
        AuctionStatus::HandoverPending,
        AuctionStatus::Disputed,
        AuctionStatus::Rejected,
    ];

    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionRefundRepository $refunds,
        private readonly AuctionSettlementRepository $settlements,
        private readonly PlanNonWinnerDepositRefundsAction $nonWinnerDeposits,
        private readonly ResolveSellerDepositDispositionAction $sellerDepositDisposition,
        private readonly SellerDepositDispositionResolver $sellerDepositResolver,
        private readonly RefundAuctionDepositAction $refundDeposit,
    ) {}

    public function execute(AuctionCancellationContextDTO $context): Auction
    {
        if (trim($context->reasonText) === '') {
            throw AuctionException::domain('cancellation_reason_required');
        }

        return $this->transaction->run(function () use ($context): Auction {
            $auction = $this->auctions->lockForStateChange($context->auctionId)->loadMissing(['configurationVersion', 'winningBid']);

            if ($auction->status === AuctionStatus::Cancelled) {
                return $auction->refresh()->load('settlement');
            }

            $this->assertCancellationAllowed($auction);

            $settlement = $this->settlements->lockCurrentSettlementForPayment($auction->id);
            $currentWinnerUserId = $settlement?->winner_id ?? $auction->winningBid?->bidder_id;
            $sellerDeposit = $this->deposits->lockSellerDepositForAuction($auction->id);
            $payments = $this->payments->lockSucceededTransactionsForAuction($auction->id);

            $plan = $this->buildPlan($auction, $context, $settlement, $sellerDeposit, $payments);
            $this->markCancellationStarted($auction, $context, $plan);

            if ($settlement) {
                $this->settlements->closeAsCancelled($settlement, $context->reasonText, $context->actorId);
            }

            $this->supersedePendingPaymentSubmissions($auction, $context);
            $this->closeUnpaidBidderDepositObligations($auction, $context);
            $this->releaseCurrentWinnerDeposit($auction, $currentWinnerUserId, $context);
            $this->createWinnerPaymentRefundPlans($auction, $payments, $context);

            $sellerDecision = $this->sellerDepositDisposition->execute(
                $auction,
                'cancellation',
                $context->actorId,
                $context->actorType,
                $context->reasonText,
                [
                    'auction_status_before' => $auction->status,
                    'fault' => $this->sellerDepositFault($context),
                    'policy_key' => $this->sellerDepositPolicyKey($context),
                    ...$context->metadata,
                ]
            );

            $auction = $this->stateMachine->transition(
                $auction,
                AuctionStatus::Cancelled,
                $context->actorId,
                $context->actorType,
                $context->reasonText,
                $context->auditMetadata()
            );

            $this->nonWinnerDeposits->execute(
                $auction,
                'cancelled',
                $context->actorId,
                $context->actorType,
                $currentWinnerUserId ? [(int) $currentWinnerUserId] : []
            );

            $auction->forceFill([
                'cancellation_operation_key' => $context->operationKey(),
                'cancellation_trigger' => $context->trigger->value,
                'cancellation_reason_code' => $context->reasonCode,
                'cancellation_reason_text' => $context->reasonText,
                'cancellation_liability' => $context->liability,
                'financial_cancellation_completed_at' => Carbon::now(),
                'financial_cancellation_manual_review_required' => $plan->manualReviewRequired
                    || $sellerDecision->disposition === SellerDepositDisposition::ManualReview,
            ]);
            $this->auctions->save($auction);

            $this->audit->log('auction.cancellation_financial_plan_created', $auction, $context->actorId, $context->actorType, [
                ...$context->auditMetadata(),
                ...$plan->metadata(),
                'seller_deposit_disposition_result' => $sellerDecision->disposition->value,
            ]);
            $this->audit->outbox('auction.cancellation_financial_plan_created', $auction, [
                'auction_public_id' => $auction->public_id,
                ...$context->auditMetadata(),
                ...$plan->metadata(),
            ]);
            $this->audit->outbox('auction.cancelled', $auction, [
                'auction_public_id' => $auction->public_id,
                'trigger' => $context->trigger->value,
                'actor_id' => $context->actorId,
                'actor_type' => $context->actorType,
                'reason_code' => $context->reasonCode,
            ]);

            return $auction->refresh()->load('settlement');
        });
    }

    private function assertCancellationAllowed(Auction $auction): void
    {
        if (! in_array($auction->status, self::CANCELLABLE_STATUSES, true)) {
            throw AuctionException::domain('auction_cancellation_not_allowed');
        }
    }

    private function buildPlan(
        Auction $auction,
        AuctionCancellationContextDTO $context,
        ?AuctionSettlement $settlement,
        ?AuctionDeposit $sellerDeposit,
        \Illuminate\Support\Collection $payments,
    ): AuctionCancellationPlanDTO {
        $sellerDecision = $this->sellerDepositResolver->resolve(
            $auction,
            'cancellation',
            $sellerDeposit,
            $context->actorType,
            $context->reasonText,
            [
                'auction_status_before' => $auction->status,
                'actor_id' => $context->actorId,
                'fault' => $this->sellerDepositFault($context),
                'policy_key' => $this->sellerDepositPolicyKey($context),
                ...$context->metadata,
            ]
        );

        $manualReviewItems = [];
        if ($sellerDecision->disposition === SellerDepositDisposition::ManualReview) {
            $manualReviewItems[] = 'seller_deposit';
        }
        if ($context->liability === 'manual_review') {
            $manualReviewItems[] = 'cancellation_liability';
        }

        return new AuctionCancellationPlanDTO(
            auctionId: $auction->id,
            trigger: $context->trigger,
            operationKey: $context->operationKey(),
            currentSettlementId: $settlement?->id,
            currentWinnerUserId: $settlement?->winner_id ?? $auction->winningBid?->bidder_id,
            pendingSubmissionCount: $this->payments->lockPendingReviewSubmissionsForAuction($auction->id)->count(),
            successfulWinnerPaymentCount: $payments->where('purpose', PaymentPurpose::WinnerSettlement)->count(),
            successfulBidderDepositPaymentCount: $payments->where('purpose', PaymentPurpose::BidderDeposit)->count(),
            sellerDepositDisposition: $sellerDecision->disposition,
            manualReviewRequired: $manualReviewItems !== [],
            manualReviewItems: $manualReviewItems,
        );
    }

    private function markCancellationStarted(Auction $auction, AuctionCancellationContextDTO $context, AuctionCancellationPlanDTO $plan): void
    {
        $this->audit->log('auction.cancellation_started', $auction, $context->actorId, $context->actorType, [
            ...$context->auditMetadata(),
            ...$plan->metadata(),
        ]);
        $this->audit->outbox('auction.cancellation_started', $auction, [
            'auction_public_id' => $auction->public_id,
            ...$context->auditMetadata(),
        ]);
    }

    private function supersedePendingPaymentSubmissions(Auction $auction, AuctionCancellationContextDTO $context): void
    {
        foreach ($this->payments->lockPendingReviewSubmissionsForAuction($auction->id) as $submission) {
            $submission->forceFill([
                'status' => PaymentSubmissionStatus::Rejected,
                'reviewed_by' => $context->actorId,
                'review_note' => 'auction_cancelled',
                'reviewed_at' => Carbon::now(),
            ]);
            $this->payments->save($submission);
        }
    }

    private function closeUnpaidBidderDepositObligations(Auction $auction, AuctionCancellationContextDTO $context): void
    {
        foreach ($this->deposits->lockBidderDepositsForAuction($auction->id) as $deposit) {
            if ($this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forDeposit($deposit))) {
                continue;
            }

            if (! in_array($deposit->status, [
                AuctionDepositStatus::PendingSubmission,
                AuctionDepositStatus::PendingReview,
                AuctionDepositStatus::Rejected,
                AuctionDepositStatus::Held,
                AuctionDepositStatus::AppliedToSettlement,
            ], true)) {
                continue;
            }

            $deposit->forceFill([
                'status' => AuctionDepositStatus::Rejected,
                'held_amount_minor' => 0,
                'applied_amount_minor' => 0,
                'hold_reason' => 'auction_cancelled_without_payment',
                'hold_expires_at' => null,
                'hold_metadata' => $context->auditMetadata(),
                'released_at' => Carbon::now(),
            ]);
            $this->deposits->save($deposit);
        }
    }

    private function releaseCurrentWinnerDeposit(Auction $auction, ?int $winnerUserId, AuctionCancellationContextDTO $context): void
    {
        if (! $winnerUserId) {
            return;
        }

        $deposit = $this->deposits->lockWinnerDeposit($auction->id, (int) $winnerUserId);
        if (! $deposit) {
            return;
        }

        $payment = $this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forDeposit($deposit));
        if (! $payment) {
            if (in_array($deposit->status, [AuctionDepositStatus::Held, AuctionDepositStatus::AppliedToSettlement], true)) {
                $deposit->forceFill([
                    'status' => AuctionDepositStatus::Rejected,
                    'held_amount_minor' => 0,
                    'applied_amount_minor' => 0,
                    'hold_reason' => 'auction_cancelled_without_payment',
                    'hold_metadata' => $context->auditMetadata(),
                    'released_at' => Carbon::now(),
                ]);
                $this->deposits->save($deposit);
            }

            return;
        }

        if ($this->refunds->lockActiveOrSucceededForDeposit($deposit->id)->isNotEmpty()) {
            if ($deposit->status !== AuctionDepositStatus::RefundPending) {
                $deposit->forceFill(['status' => AuctionDepositStatus::RefundPending]);
                $this->deposits->save($deposit);
            }

            return;
        }

        $this->refundDeposit->execute($deposit, "auction_cancelled: {$context->reasonText}", $context->actorId);
    }

    private function createWinnerPaymentRefundPlans(
        Auction $auction,
        \Illuminate\Support\Collection $payments,
        AuctionCancellationContextDTO $context,
    ): void {
        $provider = (string) config('auction.refunds.provider', 'manual');

        foreach ($payments as $payment) {
            if ($payment->purpose !== PaymentPurpose::WinnerSettlement) {
                continue;
            }

            if ($this->refunds->lockActiveOrSucceededForPayment($payment->id)->isNotEmpty()) {
                continue;
            }

            $settlement = $payment->submission?->settlement;
            if (! $settlement) {
                continue;
            }

            $this->refunds->firstOrCreateRefund(
                ['provider' => $provider, 'idempotency_key' => "{$context->operationKey()}:winner-payment:{$payment->id}"],
                [
                    'auction_id' => $auction->id,
                    'deposit_id' => null,
                    'payment_transaction_id' => $payment->id,
                    'obligation_type' => 'settlement',
                    'obligation_id' => $settlement->id,
                    'user_id' => $payment->user_id,
                    'status' => RefundTransactionStatus::Pending,
                    'amount_minor' => $payment->amount_minor,
                    'held_refund_amount_minor' => 0,
                    'applied_refund_amount_minor' => 0,
                    'currency_code' => $payment->currency_code,
                    'reason' => "auction_cancelled: {$context->reasonText}",
                ]
            );
        }
    }

    private function sellerDepositFault(AuctionCancellationContextDTO $context): string
    {
        return match ($context->trigger) {
            AuctionCancellationTrigger::PlatformFault => 'platform_fault',
            AuctionCancellationTrigger::SellerBreach => 'seller_fault',
            AuctionCancellationTrigger::Fraud,
            AuctionCancellationTrigger::Compliance => 'fraud_or_compliance',
            default => match ($context->liability) {
                'platform' => 'platform_fault',
                'seller' => 'seller_fault',
                'manual_review' => 'fraud_or_compliance',
                default => 'neutral',
            },
        };
    }

    private function sellerDepositPolicyKey(AuctionCancellationContextDTO $context): ?string
    {
        if ($context->trigger === AuctionCancellationTrigger::SellerRequested) {
            return null;
        }

        if ($context->actorType === 'system') {
            return match ($this->sellerDepositFault($context)) {
                'platform_fault' => 'system_cancellation_platform_fault',
                'seller_fault' => 'system_cancellation_seller_fault',
                default => 'system_cancellation_neutral',
            };
        }

        return match ($this->sellerDepositFault($context)) {
            'platform_fault' => 'admin_cancellation_platform_fault',
            'seller_fault' => 'admin_cancellation_seller_fault',
            'fraud_or_compliance' => 'admin_cancellation_fraud_or_compliance',
            default => 'admin_cancellation_neutral',
        };
    }
}
