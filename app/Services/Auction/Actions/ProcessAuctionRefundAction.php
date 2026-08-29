<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\RefundProcessingOutcome;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\DTO\Auction\RefundProcessingResult;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Services\Auction\Refunds\RefundProcessorFactory;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionRefundCompletion;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class ProcessAuctionRefundAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRefundRepository $refunds,
        private readonly RefundProcessorFactory $processors,
        private readonly AuctionRefundCompletion $completion,
    ) {}

    public function execute(RefundTransaction $refund): RefundTransaction
    {
        [$refundId, $processingToken] = $this->claimProcessingLease($refund);

        $claimedRefund = RefundTransaction::findOrFail($refundId);
        try {
            $result = $this->processors->forRefund($claimedRefund)->process($claimedRefund);
        } catch (Throwable $exception) {
            $result = RefundProcessingResult::retryableFailure(
                'processor_exception',
                $exception->getMessage()
            );
        }

        return $this->applyResult($refundId, $processingToken, $result);
    }

    private function claimProcessingLease(RefundTransaction $refund): array
    {
        return $this->transaction->run(function () use ($refund): array {
            $refund = $this->refunds->lockForConfirmation($refund->id);
            $now = Carbon::now();

            if (in_array($refund->status, [
                RefundTransactionStatus::Succeeded,
                RefundTransactionStatus::Cancelled,
                RefundTransactionStatus::ManualReview,
            ], true)) {
                throw AuctionException::domain('refund_not_processable');
            }

            if (
                $refund->status === RefundTransactionStatus::Failed
                && $refund->next_retry_at
                && $refund->next_retry_at->isFuture()
            ) {
                throw AuctionException::domain('refund_not_processable');
            }

            if (
                $refund->status === RefundTransactionStatus::Processing
                && $refund->lease_expires_at
                && $refund->lease_expires_at->isFuture()
            ) {
                throw AuctionException::domain('refund_lease_already_acquired');
            }

            $token = (string) Str::uuid();
            $refund->forceFill([
                'status' => RefundTransactionStatus::Processing,
                'attempt_count' => (int) $refund->attempt_count + 1,
                'processing_started_at' => $now,
                'processing_token' => $token,
                'lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
                'last_error' => null,
            ]);
            $this->refunds->save($refund);

            $this->audit->log('auction.refund_processing_started', $refund->auction, null, 'system', [
                'refund_public_id' => $refund->public_id,
                'attempt_count' => $refund->attempt_count,
                'provider' => $refund->provider,
                'lease_expires_at' => $refund->lease_expires_at?->toIso8601String(),
            ]);
            $this->audit->outbox('auction.refund_processing', $refund->auction, [
                'refund_public_id' => $refund->public_id,
                'attempt_count' => $refund->attempt_count,
                'status' => RefundTransactionStatus::Processing->value,
            ]);

            return [$refund->id, $token];
        });
    }

    private function applyResult(int $refundId, string $processingToken, RefundProcessingResult $result): RefundTransaction
    {
        return $this->transaction->run(function () use ($refundId, $processingToken, $result): RefundTransaction {
            $refund = $this->refunds->lockForConfirmation($refundId);

            if ($refund->processing_token !== $processingToken) {
                throw AuctionException::domain('refund_processing_token_mismatch');
            }

            return match ($result->outcome) {
                RefundProcessingOutcome::Succeeded => $this->completeSuccess($refund, $processingToken, $result),
                RefundProcessingOutcome::RetryableFailure => $this->recordRetryableFailure($refund, $result),
                RefundProcessingOutcome::NonRetryableFailure,
                RefundProcessingOutcome::ManualReviewRequired => $this->moveToManualReview($refund, $result),
            };
        });
    }

    private function completeSuccess(RefundTransaction $refund, string $processingToken, RefundProcessingResult $result): RefundTransaction
    {
        if (! $result->providerRefundId) {
            return $this->moveToManualReview($refund, RefundProcessingResult::manualReviewRequired(
                'missing_provider_refund_id',
                'Provider reported success without a refund reference.',
                $result->providerResponse
            ));
        }

        return $this->completion->completeSucceeded(
            $refund,
            $result->providerRefundId,
            $processingToken,
            $result->providerResponse
        );
    }

    private function recordRetryableFailure(RefundTransaction $refund, RefundProcessingResult $result): RefundTransaction
    {
        if ((int) $refund->attempt_count >= $this->maxAttempts()) {
            return $this->moveToManualReview($refund, $result);
        }

        $nextRetryAt = Carbon::now()->addSeconds($this->backoffSeconds((int) $refund->attempt_count));
        $error = $this->errorMessage($result);

        $refund->forceFill([
            'status' => RefundTransactionStatus::Failed,
            'last_error' => $error,
            'failure_reason' => $error,
            'provider_response' => $result->providerResponse,
            'failed_at' => Carbon::now(),
            'next_retry_at' => $nextRetryAt,
            'processing_token' => null,
            'lease_expires_at' => null,
        ]);
        $this->refunds->save($refund);

        $this->audit->log('auction.refund_processing_failed', $refund->auction, null, 'system', [
            'refund_public_id' => $refund->public_id,
            'attempt_count' => $refund->attempt_count,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'next_retry_at' => $nextRetryAt->toIso8601String(),
        ]);
        $this->audit->log('auction.refund_retry_scheduled', $refund->auction, null, 'system', [
            'refund_public_id' => $refund->public_id,
            'attempt_count' => $refund->attempt_count,
            'next_retry_at' => $nextRetryAt->toIso8601String(),
        ]);
        $this->audit->outbox('auction.refund_failed', $refund->auction, [
            'refund_public_id' => $refund->public_id,
            'attempt_count' => $refund->attempt_count,
            'error_code' => $result->errorCode,
            'next_retry_at' => $nextRetryAt->toIso8601String(),
            'status' => RefundTransactionStatus::Failed->value,
        ]);

        return $refund->refresh();
    }

    private function moveToManualReview(RefundTransaction $refund, RefundProcessingResult $result): RefundTransaction
    {
        $error = $this->errorMessage($result);
        $refund->forceFill([
            'status' => RefundTransactionStatus::ManualReview,
            'last_error' => $error,
            'failure_reason' => $error,
            'provider_response' => $result->providerResponse,
            'failed_at' => Carbon::now(),
            'next_retry_at' => null,
            'processing_token' => null,
            'lease_expires_at' => null,
        ]);
        $this->refunds->save($refund);

        $this->audit->log('auction.refund_moved_to_manual_review', $refund->auction, null, 'system', [
            'refund_public_id' => $refund->public_id,
            'attempt_count' => $refund->attempt_count,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ]);
        $this->audit->outbox('auction.refund_manual_review', $refund->auction, [
            'refund_public_id' => $refund->public_id,
            'attempt_count' => $refund->attempt_count,
            'error_code' => $result->errorCode,
            'status' => RefundTransactionStatus::ManualReview->value,
        ]);

        return $refund->refresh();
    }

    private function errorMessage(RefundProcessingResult $result): string
    {
        return trim(($result->errorCode ? "{$result->errorCode}: " : '').($result->errorMessage ?? 'Refund processing failed.'));
    }

    private function leaseSeconds(): int
    {
        return max(1, (int) config('auction.refunds.lease_seconds', 300));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('auction.refunds.max_attempts', 5));
    }

    private function backoffSeconds(int $attempt): int
    {
        $schedule = array_values((array) config('auction.refunds.backoff_seconds', [60, 300, 900, 3600]));

        return (int) ($schedule[max(0, $attempt - 1)] ?? end($schedule) ?: 60);
    }
}
