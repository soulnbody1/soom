<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\PaymentSubmission;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\FinancialObligationKey;
use App\Services\Auction\Support\PaymentEligibilityRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

final class ReviewPaymentSubmissionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionSettlementRepository $settlements,
        private readonly PaymentEligibilityRule $eligibility,
        private readonly ApplyPaymentSucceededAction $applyPaymentSucceeded,
    ) {}

    public function approve(
        PaymentSubmission $submission,
        int $adminId,
        string $note = '',
        string $providerTransactionId = '',
        bool $overrideDeadline = false,
        string $overrideReason = ''
    ): PaymentSubmission {
        return $this->transaction->run(function () use ($submission, $adminId, $note, $providerTransactionId, $overrideDeadline, $overrideReason): PaymentSubmission {
            $submission = $this->payments->lockSubmissionForReview($submission->id);

            if ($submission->status !== PaymentSubmissionStatus::PendingReview) {
                return $submission->load(['auction', 'deposit', 'settlement', 'transaction']);
            }

            $auction = $this->payments->lockSubmissionAuction($submission);

            $deposit = null;
            $participant = null;
            $settlement = null;
            if (in_array($submission->purpose, [PaymentPurpose::SellerDeposit, PaymentPurpose::BidderDeposit], true)) {
                $deposit = $this->payments->lockSubmissionDeposit($submission);
                if ($submission->purpose === PaymentPurpose::BidderDeposit) {
                    $participant = $this->participants->lockParticipant($auction->id, (int) $submission->user_id);
                }
            }

            if ($submission->purpose === PaymentPurpose::WinnerSettlement) {
                $settlement = $this->payments->lockSubmissionSettlement($submission);
                $currentSettlement = $this->settlements->lockCurrentSettlementForPayment($auction->id);
                if (! $currentSettlement || $currentSettlement->id !== $settlement->id) {
                    throw AuctionException::domain('payment_target_not_current');
                }
            }

            $originalDeadline = $this->eligibility->assertCanApproveSubmission(
                $auction,
                $submission,
                $deposit,
                $participant,
                $settlement,
                $overrideDeadline,
                $overrideReason,
                $adminId
            );

            $obligationKey = FinancialObligationKey::forSubmission($submission);
            if ($this->payments->lockSucceededTransactionForObligation($obligationKey)) {
                throw AuctionException::domain('payment_obligation_already_paid');
            }

            $providerTransactionId = $this->trustedProviderTransactionId($submission, $providerTransactionId);
            if ($this->payments->providerTransactionIdExists('manual', $providerTransactionId, $submission->id)) {
                throw AuctionException::domain('duplicate_provider_transaction');
            }

            $submission->forceFill([
                'status' => PaymentSubmissionStatus::Approved,
                'reviewed_by' => $adminId,
                'review_note' => $note,
                'reviewed_at' => Carbon::now(),
            ]);
            if ($originalDeadline) {
                $submission->forceFill([
                    'overridden_by' => $adminId,
                    'overridden_at' => Carbon::now(),
                    'override_reason' => trim($overrideReason),
                    'original_deadline' => $originalDeadline,
                ]);
            }
            $this->payments->save($submission);

            try {
                $transaction = $this->payments->firstOrCreateTransaction(
                    [
                        'purpose' => $submission->purpose->value,
                        'idempotency_key' => "submission:{$submission->id}:approved",
                    ],
                    [
                        'payment_submission_id' => $submission->id,
                        'auction_id' => $auction->id,
                        'user_id' => $submission->user_id,
                        'status' => PaymentTransactionStatus::Succeeded,
                        'amount_minor' => $submission->amount_minor,
                        'currency_code' => $submission->currency_code,
                        'provider' => 'manual',
                        'provider_transaction_id' => $providerTransactionId,
                        'successful_obligation_key' => $obligationKey,
                        'processed_at' => Carbon::now(),
                    ]
                );
            } catch (QueryException $exception) {
                if ($this->isProviderTransactionCollision($exception)) {
                    throw AuctionException::domain('duplicate_provider_transaction');
                }

                if ($this->isPaymentUniquenessCollision($exception)) {
                    throw AuctionException::domain('payment_obligation_already_paid');
                }

                throw $exception;
            }

            $this->applyPaymentSucceeded->execute($transaction, $adminId, 'admin');

            $this->audit->log('auction.payment_approved', $auction, $adminId, 'admin', [
                'submission_public_id' => $submission->public_id,
                'purpose' => $submission->purpose->value,
                'deadline_overridden' => $originalDeadline !== null,
                'original_deadline' => $originalDeadline?->toIso8601String(),
            ]);
            $this->audit->outbox('auction.payment_approved', $auction, [
                'payment_submission_id' => $submission->id,
                'user_id' => $submission->user_id,
                'purpose' => $submission->purpose->value,
            ]);

            return $submission->refresh()->load(['auction', 'deposit', 'settlement', 'transaction']);
        });
    }

    public function reject(PaymentSubmission $submission, int $adminId, string $note): PaymentSubmission
    {
        if (trim($note) === '') {
            throw AuctionException::paymentRejected('payment_rejection_reason_required');
        }

        return $this->transaction->run(function () use ($submission, $adminId, $note): PaymentSubmission {
            $submission = $this->payments->lockSubmissionForReview($submission->id);

            if ($submission->status !== PaymentSubmissionStatus::PendingReview) {
                return $submission;
            }

            $submission->forceFill([
                'status' => PaymentSubmissionStatus::Rejected,
                'reviewed_by' => $adminId,
                'review_note' => $note,
                'reviewed_at' => Carbon::now(),
            ]);
            $this->payments->save($submission);

            if ($submission->deposit_id) {
                $deposit = $this->payments->lockSubmissionDeposit($submission);
                $deposit->forceFill([
                    'status' => AuctionDepositStatus::PendingSubmission,
                    'released_at' => null,
                ]);
                $this->deposits->save($deposit);
            }

            $this->audit->log('auction.payment_rejected', $submission->auction, $adminId, 'admin', [
                'submission_public_id' => $submission->public_id,
                'reason' => $note,
            ]);
            $this->audit->outbox('auction.payment_rejected', $submission->auction, [
                'payment_submission_id' => $submission->id,
                'user_id' => $submission->user_id,
                'purpose' => $submission->purpose->value,
                'reason' => $note,
            ]);

            return $submission->refresh()->load(['auction', 'deposit']);
        });
    }

    private function trustedProviderTransactionId(PaymentSubmission $submission, string $providerTransactionId): string
    {
        $providerTransactionId = trim($providerTransactionId);

        return $providerTransactionId !== ''
            ? $providerTransactionId
            : "manual:submission:{$submission->id}:approved";
    }

    private function isPaymentUniquenessCollision(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'uq_payment_successful_obligation')
            || str_contains($message, 'uq_payment_transaction_submission')
            || str_contains($message, 'duplicate entry');
    }

    private function isProviderTransactionCollision(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'uniq_provider_txn');
    }
}
