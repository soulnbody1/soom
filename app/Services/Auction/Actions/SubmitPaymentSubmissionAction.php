<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class SubmitPaymentSubmissionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit
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
        $existing = PaymentSubmission::with(['paymentMethod', 'deposit', 'settlement'])
            ->where('auction_id', $auction->id)
            ->where('user_id', $userId)
            ->where('purpose', $purpose->value)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $method = PaymentMethod::where('public_id', $paymentMethodPublicId)
            ->where('is_active', true)
            ->firstOrFail();

        [$deposit, $settlement, $amount] = $this->transaction->run(function () use ($auction, $userId, $purpose): array {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();

            return $this->target($auction, $userId, $purpose);
        });

        $path = $receipt->store("auction-payments/{$auction->public_id}", 'spaces_private');

        try {
            return $this->transaction->run(function () use ($auction, $userId, $purpose, $method, $receipt, $idempotencyKey, $providerReference, $deposit, $settlement, $amount, $path): PaymentSubmission {
                $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
                $submission = PaymentSubmission::firstOrCreate(
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

                if ($deposit && $deposit->status === AuctionDepositStatus::PendingSubmission) {
                    $deposit->forceFill([
                        'status' => AuctionDepositStatus::PendingReview,
                        'submitted_at' => Carbon::now(),
                        'idempotency_key' => $idempotencyKey,
                    ])->save();
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

            $deposit = AuctionDeposit::firstOrCreate(
                ['auction_id' => $auction->id, 'user_id' => $userId, 'type' => 'seller'],
                [
                    'status' => AuctionDepositStatus::PendingSubmission,
                    'required_amount_minor' => $auction->seller_deposit_amount_minor,
                    'currency_code' => $auction->currency_code,
                ]
            );

            return [$deposit, null, $auction->seller_deposit_amount_minor];
        }

        if ($purpose === PaymentPurpose::BidderDeposit) {
            $participant = AuctionParticipant::where('auction_id', $auction->id)->where('user_id', $userId)->first();

            if (! $participant) {
                throw new AuctionException(__('auction.errors.registration_required'));
            }

            $deposit = AuctionDeposit::firstOrCreate(
                ['auction_id' => $auction->id, 'user_id' => $userId, 'type' => 'bidder'],
                [
                    'participant_id' => $participant->id,
                    'status' => AuctionDepositStatus::PendingSubmission,
                    'required_amount_minor' => $auction->bidder_deposit_amount_minor,
                    'currency_code' => $auction->currency_code,
                ]
            );

            return [$deposit, null, $auction->bidder_deposit_amount_minor];
        }

        $settlement = AuctionSettlement::where('auction_id', $auction->id)->where('winner_id', $userId)->first();

        if (! $settlement) {
            throw new AuctionException(__('auction.errors.settlement_payment_unavailable'));
        }

        return [null, $settlement, max(0, $settlement->amount_due_minor - $settlement->amount_paid_minor)];
    }
}
