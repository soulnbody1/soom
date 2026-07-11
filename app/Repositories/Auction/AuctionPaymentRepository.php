<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;

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

    /**
     * Create or find payment submission (idempotent).
     * Used by SubmitPaymentSubmissionAction.
     */
    public function firstOrCreateSubmission(array $uniqueAttributes, array $defaults): PaymentSubmission
    {
        return PaymentSubmission::firstOrCreate($uniqueAttributes, $defaults);
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

    /**
     * Save submission model after in-memory changes.
     */
    public function save(PaymentSubmission $submission): void
    {
        $submission->save();
    }
}
