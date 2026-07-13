<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use Illuminate\Support\Collection;

final class AuctionPaymentRepository
{
    /**
     * Find existing payment submission by idempotency key.
     * Used by SubmitPaymentSubmissionAction for duplicate detection.
     */
    public function findSubmissionByIdempotencyKey(
        int $auctionId,
        int $userId,
        string $purpose,
        string $idempotencyKey
    ): ?PaymentSubmission {
        return PaymentSubmission::with(['paymentMethod', 'deposit', 'settlement'])
            ->where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Find active payment method by public_id.
     * Used by SubmitPaymentSubmissionAction.
     */
    public function findActivePaymentMethod(string $publicId): PaymentMethod
    {
        return PaymentMethod::where('public_id', $publicId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    public function createPaymentMethod(array $attributes): PaymentMethod
    {
        return PaymentMethod::create($attributes);
    }

    public function updatePaymentMethod(PaymentMethod $paymentMethod, array $attributes): PaymentMethod
    {
        $paymentMethod->update($attributes);

        return $paymentMethod->refresh();
    }

    /**
     * Create or find payment submission (idempotent).
     * Used by SubmitPaymentSubmissionAction.
     */
    public function firstOrCreateSubmission(array $uniqueAttributes, array $defaults): PaymentSubmission
    {
        return PaymentSubmission::firstOrCreate($uniqueAttributes, $defaults);
    }

    public function findPendingReviewSubmissionForDeposit(int $depositId): ?PaymentSubmission
    {
        return PaymentSubmission::where('deposit_id', $depositId)
            ->where('status', PaymentSubmissionStatus::PendingReview->value)
            ->lockForUpdate()
            ->first();
    }

    public function findPendingReviewSubmissionForSettlement(int $settlementId): ?PaymentSubmission
    {
        return PaymentSubmission::where('settlement_id', $settlementId)
            ->where('status', PaymentSubmissionStatus::PendingReview->value)
            ->lockForUpdate()
            ->first();
    }

    public function lockPendingReviewSubmissionsForSettlement(int $settlementId): Collection
    {
        return PaymentSubmission::where('settlement_id', $settlementId)
            ->where('status', PaymentSubmissionStatus::PendingReview->value)
            ->lockForUpdate()
            ->get();
    }

    public function lockPendingReviewSubmissionsForAuction(int $auctionId): Collection
    {
        return PaymentSubmission::where('auction_id', $auctionId)
            ->where('status', PaymentSubmissionStatus::PendingReview->value)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lock a payment submission for review.
     * Used by ReviewPaymentSubmissionAction.
     */
    public function lockSubmissionForReview(int $submissionId): PaymentSubmission
    {
        return PaymentSubmission::whereKey($submissionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock the auction related to a submission.
     * Used by ReviewPaymentSubmissionAction.
     */
    public function lockSubmissionAuction(PaymentSubmission $submission): \App\Models\Auction\Auction
    {
        return $submission->auction()->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock deposit related to a submission.
     * Used by ReviewPaymentSubmissionAction.
     */
    public function lockSubmissionDeposit(PaymentSubmission $submission): \App\Models\Auction\AuctionDeposit
    {
        return $submission->deposit()->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock settlement related to a submission.
     * Used by ReviewPaymentSubmissionAction.
     */
    public function lockSubmissionSettlement(PaymentSubmission $submission): \App\Models\Auction\AuctionSettlement
    {
        return $submission->settlement()->lockForUpdate()->firstOrFail();
    }

    /**
     * Create payment transaction (idempotent via firstOrCreate).
     * Used by ReviewPaymentSubmissionAction.
     */
    public function firstOrCreateTransaction(array $uniqueAttributes, array $defaults): PaymentTransaction
    {
        return PaymentTransaction::firstOrCreate($uniqueAttributes, $defaults);
    }

    public function providerTransactionIdExists(string $provider, string $providerTransactionId, ?int $exceptSubmissionId = null): bool
    {
        return PaymentTransaction::where('provider', $provider)
            ->where('provider_transaction_id', $providerTransactionId)
            ->when($exceptSubmissionId, fn ($query) => $query->where('payment_submission_id', '!=', $exceptSubmissionId))
            ->exists();
    }

    public function lockSucceededTransactionForObligation(string $obligationKey): ?PaymentTransaction
    {
        return PaymentTransaction::where('successful_obligation_key', $obligationKey)
            ->where('status', PaymentTransactionStatus::Succeeded->value)
            ->lockForUpdate()
            ->first();
    }

    public function lockSucceededTransactionsForAuction(int $auctionId): Collection
    {
        return PaymentTransaction::with(['submission.deposit', 'submission.settlement'])
            ->where('auction_id', $auctionId)
            ->where('status', PaymentTransactionStatus::Succeeded->value)
            ->lockForUpdate()
            ->get();
    }

    public function lockTransactionForRefund(int $paymentTransactionId): PaymentTransaction
    {
        return PaymentTransaction::whereKey($paymentTransactionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function saveTransaction(PaymentTransaction $transaction): void
    {
        $transaction->save();
    }

    /**
     * Save submission model after in-memory changes.
     */
    public function save(PaymentSubmission $submission): void
    {
        $submission->save();
    }
}
