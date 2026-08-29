<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\PaymentSubmission;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\PaymentObligationResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class SubmitPaymentSubmissionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionDepositRepository $deposits,
        private readonly PaymentObligationResolver $obligations,
    ) {}

    public function execute(
        Auction $auction,
        int $userId,
        PaymentPurpose $purpose,
        string $paymentMethodPublicId,
        UploadedFile $receipt,
        string $idempotencyKey,
        ?string $providerReference = null
    ): PaymentSubmission {
        $existing = $this->payments->findSubmissionByIdempotencyKey(
            $auction->id,
            $userId,
            $purpose->value,
            $idempotencyKey
        );

        if ($existing) {
            return $existing->load(['paymentMethod', 'deposit', 'settlement']);
        }

        $method = $this->payments->findActivePaymentMethod($paymentMethodPublicId);

        $this->transaction->run(function () use ($auction, $userId, $purpose): void {
            $auction = $this->auctions->lockAuctionForPayment($auction->id);
            $this->obligations->resolve($auction, $userId, $purpose);
        });

        $path = $receipt->store("auction-payments/{$auction->public_id}", 'spaces_private');

        try {
            return $this->transaction->run(function () use ($auction, $userId, $purpose, $method, $receipt, $idempotencyKey, $providerReference, $path): PaymentSubmission {
                $auction = $this->auctions->lockAuctionForPayment($auction->id);
                $obligation = $this->obligations->resolve($auction, $userId, $purpose);
                $deposit = $obligation->deposit;
                $settlement = $obligation->settlement;

                if ($obligation->amountMinor <= 0) {
                    throw AuctionException::domain('zero_payment_not_allowed');
                }

                $this->ensurePaymentSubmissionCanBeCreated($deposit, $settlement, $obligation->key());

                $submission = $this->payments->firstOrCreateSubmission(
                    [
                        'auction_id' => $auction->id,
                        'user_id' => $userId,
                        'purpose' => $purpose->value,
                        'idempotency_key' => $idempotencyKey,
                    ],
                    [
                        'deposit_id' => $deposit?->id,
                        'settlement_id' => $settlement?->id,
                        'payment_method_id' => $method->id,
                        'status' => PaymentSubmissionStatus::PendingReview,
                        'amount_minor' => $obligation->amountMinor,
                        'currency_code' => $obligation->currencyCode,
                        'receipt_disk' => 'spaces_private',
                        'receipt_path' => $path,
                        'receipt_mime_type' => (string) $receipt->getMimeType(),
                        'receipt_size_bytes' => (int) $receipt->getSize(),
                        'provider_reference' => $providerReference,
                        'submitted_at' => Carbon::now(),
                    ]
                );

                if (! $submission->wasRecentlyCreated) {
                    Storage::disk('spaces_private')->delete($path);

                    return $submission->load(['paymentMethod', 'deposit', 'settlement']);
                }

                if ($deposit && in_array($deposit->status, [AuctionDepositStatus::PendingSubmission, AuctionDepositStatus::Rejected], true)) {
                    $deposit->forceFill([
                        'status' => AuctionDepositStatus::PendingReview,
                        'submitted_at' => Carbon::now(),
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    $this->deposits->save($deposit);
                }

                $this->audit->log('auction.payment_submitted', $auction, $userId, 'user', [
                    'purpose' => $purpose->value,
                    'submission_public_id' => $submission->public_id,
                ]);
                $this->audit->outbox('auction.payment_submitted', $auction, [
                    'auction_public_id' => $auction->public_id,
                    'payment_submission_id' => $submission->id,
                    'user_id' => $userId,
                    'purpose' => $purpose->value,
                ]);

                return $submission->load(['paymentMethod', 'deposit', 'settlement']);
            });
        } catch (\Throwable $exception) {
            Storage::disk('spaces_private')->delete($path);

            throw $exception;
        }
    }

    private function ensurePaymentSubmissionCanBeCreated($deposit, $settlement, string $obligationKey): void
    {
        if ($this->payments->lockSucceededTransactionForObligation($obligationKey)) {
            throw AuctionException::domain('payment_obligation_already_paid');
        }

        if ($deposit) {
            if (in_array($deposit->status, [
                AuctionDepositStatus::Held,
                AuctionDepositStatus::AppliedToSettlement,
                AuctionDepositStatus::RefundPending,
                AuctionDepositStatus::Refunded,
                AuctionDepositStatus::Forfeited,
            ], true)) {
                throw AuctionException::domain('payment_obligation_already_paid');
            }

            if ($this->payments->findPendingReviewSubmissionForDeposit($deposit->id)) {
                throw AuctionException::domain('active_payment_submission_exists');
            }

            return;
        }

        if ((int) $settlement->remaining_amount_minor <= 0) {
            throw AuctionException::domain('payment_obligation_already_paid');
        }

        if ($this->payments->findPendingReviewSubmissionForSettlement($settlement->id)) {
            throw AuctionException::domain('active_payment_submission_exists');
        }
    }
}
