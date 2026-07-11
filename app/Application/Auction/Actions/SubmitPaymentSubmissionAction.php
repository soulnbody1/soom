<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionAudit;
use App\Application\Auction\Services\AuctionTransaction;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

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
        int $paymentMethodId,
        UploadedFile $receipt,
        string $idempotencyKey,
        ?string $providerReference = null
    ): PaymentSubmission {
        return $this->transaction->run(function () use ($auction, $userId, $purpose, $paymentMethodId, $receipt, $idempotencyKey, $providerReference): PaymentSubmission {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $method = PaymentMethod::whereKey($paymentMethodId)->where('is_active', true)->firstOrFail();

            [$deposit, $settlement, $amount] = $this->target($auction, $userId, $purpose);
            $path = $receipt->store("auction-payments/{$auction->public_id}", 'spaces');

            $submission = PaymentSubmission::firstOrCreate(
                ['user_id' => $userId, 'purpose' => $purpose->value, 'idempotency_key' => $idempotencyKey],
                [
                    'auction_id' => $auction->id,
                    'deposit_id' => $deposit?->id,
                    'settlement_id' => $settlement?->id,
                    'payment_method_id' => $method->id,
                    'status' => PaymentSubmissionStatus::PendingReview,
                    'amount_minor' => $amount,
                    'currency_code' => $auction->currency_code,
                    'receipt_disk' => 'spaces',
                    'receipt_path' => $path,
                    'receipt_mime_type' => (string) $receipt->getMimeType(),
                    'receipt_size_bytes' => (int) $receipt->getSize(),
                    'provider_reference' => $providerReference,
                    'submitted_at' => Carbon::now(),
                ]
            );

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
    }

    private function target(Auction $auction, int $userId, PaymentPurpose $purpose): array
    {
        if ($purpose === PaymentPurpose::SellerDeposit) {
            if ($auction->seller_id !== $userId) {
                throw new AuctionException('Only seller can submit seller deposit.');
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
                throw new AuctionException('Participant registration is required before deposit submission.');
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
            throw new AuctionException('Settlement payment is not available for this user.');
        }

        return [null, $settlement, max(0, $settlement->amount_due_minor - $settlement->amount_paid_minor)];
    }
}
