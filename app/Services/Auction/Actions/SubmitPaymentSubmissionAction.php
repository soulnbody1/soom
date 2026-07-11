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
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
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
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionSettlementRepository $settlements,
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
            return $existing;
        }

        $method = $this->payments->findActivePaymentMethod($paymentMethodPublicId);

        $this->transaction->run(function () use ($auction, $userId, $purpose): void {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $this->target($auction, $userId, $purpose);
        });

        $path = $receipt->store("auction-payments/{$auction->public_id}", 'spaces_private');

        try {
            return $this->transaction->run(function () use ($auction, $userId, $purpose, $method, $receipt, $idempotencyKey, $providerReference, $path): PaymentSubmission {
                $auction = $this->auctions->lockForStateChange($auction->id);
                [$deposit, $settlement, $amount] = $this->target($auction, $userId, $purpose);

                if ($amount <= 0) {
                    throw new AuctionException(__('auction.errors.zero_payment_not_allowed'));
                }

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
                        'amount_minor' => $amount,
                        'currency_code' => $auction->currency_code,
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

                return $submission->load(['paymentMethod', 'deposit', 'settlement']);
            });
        } catch (\Throwable $exception) {
            Storage::disk('spaces_private')->delete($path);

            throw $exception;
        }
    }

    private function target(Auction $auction, int $userId, PaymentPurpose $purpose): array
    {
        if ($purpose === PaymentPurpose::SellerDeposit) {
            if ($auction->seller_id !== $userId) {
                throw new AuctionException(__('auction.errors.seller_deposit_only_seller'));
            }

            $deposit = $this->deposits->firstOrCreateDeposit(
                ['auction_id' => $auction->id, 'user_id' => $userId, 'type' => 'seller'],
                [
                    'status' => AuctionDepositStatus::PendingSubmission,
                    'required_amount_minor' => $auction->seller_deposit_amount_minor,
                    'currency_code' => $auction->currency_code,
                ]
            );

            $deposit = $this->deposits->lockPaymentDeposit($auction->id, $userId, 'seller');

            return [$deposit, null, $auction->seller_deposit_amount_minor];
        }

        if ($purpose === PaymentPurpose::BidderDeposit) {
            $participant = $this->participants->findByAuctionAndUser($auction->id, $userId);

            if (! $participant) {
                throw new AuctionException(__('auction.errors.registration_required'));
            }

            $deposit = $this->deposits->firstOrCreateDeposit(
                ['auction_id' => $auction->id, 'user_id' => $userId, 'type' => 'bidder'],
                [
                    'participant_id' => $participant->id,
                    'status' => AuctionDepositStatus::PendingSubmission,
                    'required_amount_minor' => $auction->bidder_deposit_amount_minor,
                    'currency_code' => $auction->currency_code,
                ]
            );

            $deposit = $this->deposits->lockPaymentDeposit($auction->id, $userId, 'bidder');

            return [$deposit, null, $auction->bidder_deposit_amount_minor];
        }

        $settlement = $this->settlements->lockByAuctionAndWinner($auction->id, $userId);

        if (! $settlement) {
            throw new AuctionException(__('auction.errors.settlement_payment_unavailable'));
        }

        return [null, $settlement, max(0, (int) ($settlement->remaining_amount_minor ?? ($settlement->amount_due_minor - $settlement->amount_paid_minor)))];
    }
}
