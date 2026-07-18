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
use App\Services\Auction\Support\AuctionConfigurationSnapshotReader;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\FinancialObligationKey;
use App\Services\Auction\Support\PaymentEligibilityRule;
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
        private readonly PaymentEligibilityRule $eligibility,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
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
            $auction = $this->auctions->lockAuctionForPayment($auction->id);
            $this->target($auction, $userId, $purpose);
        });

        $path = $receipt->store("auction-payments/{$auction->public_id}", 'spaces_private');

        try {
            return $this->transaction->run(function () use ($auction, $userId, $purpose, $method, $receipt, $idempotencyKey, $providerReference, $path): PaymentSubmission {
                $auction = $this->auctions->lockAuctionForPayment($auction->id);
                [$deposit, $settlement, $amount] = $this->target($auction, $userId, $purpose);

                if ($amount <= 0) {
                    throw new AuctionException(__('auction.errors.zero_payment_not_allowed'));
                }

                $obligationKey = $deposit
                    ? FinancialObligationKey::forDeposit($deposit)
                    : FinancialObligationKey::forSettlement($settlement);

                $this->ensurePaymentSubmissionCanBeCreated($deposit, $settlement, $obligationKey);

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
                        'currency_code' => $this->snapshotReader->forAuction($auction)->currency_code,
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

    private function target(Auction $auction, int $userId, PaymentPurpose $purpose): array
    {
        // The auction state is checked before the snapshot is read: a seller deposit can
        // only be submitted once the auction is approved, and an auction that was never
        // approved has no configuration snapshot to read.
        if ($purpose === PaymentPurpose::SellerDeposit) {
            $this->eligibility->assertCanSubmitSellerDeposit($auction, $userId);
        }

        $snapshot = $this->snapshotReader->forAuction($auction);

        if ($purpose === PaymentPurpose::SellerDeposit) {
            $deposit = $this->deposits->firstOrCreateDeposit(
                ['auction_id' => $auction->id, 'user_id' => $userId, 'type' => 'seller'],
                [
                    'status' => AuctionDepositStatus::PendingSubmission,
                    'required_amount_minor' => (int) $snapshot->seller_deposit_required_minor,
                    'currency_code' => $snapshot->currency_code,
                ]
            );

            $deposit = $this->deposits->lockDepositForPayment($auction->id, $userId, 'seller');
            $this->eligibility->assertDepositTarget($auction, $deposit, $userId, 'seller');
            $this->eligibility->assertPaymentDetails(
                (int) $snapshot->seller_deposit_required_minor,
                $snapshot->currency_code,
                (int) $deposit->required_amount_minor,
                (string) $deposit->currency_code
            );

            return [$deposit, null, (int) $snapshot->seller_deposit_required_minor];
        }

        if ($purpose === PaymentPurpose::BidderDeposit) {
            $participant = $this->participants->lockParticipant($auction->id, $userId);

            if (! $participant) {
                throw new AuctionException(__('auction.errors.registration_required'));
            }

            $this->eligibility->assertCanSubmitBidderDeposit($auction, $participant, $userId);

            $deposit = $this->deposits->firstOrCreateDeposit(
                ['auction_id' => $auction->id, 'user_id' => $userId, 'type' => 'bidder'],
                [
                    'participant_id' => $participant->id,
                    'status' => AuctionDepositStatus::PendingSubmission,
                    'required_amount_minor' => (int) $snapshot->bidder_deposit_required_minor,
                    'currency_code' => $snapshot->currency_code,
                ]
            );

            $deposit = $this->deposits->lockDepositForPayment($auction->id, $userId, 'bidder');
            $this->eligibility->assertDepositTarget($auction, $deposit, $userId, 'bidder', $participant);
            $this->eligibility->assertPaymentDetails(
                (int) $snapshot->bidder_deposit_required_minor,
                $snapshot->currency_code,
                (int) $deposit->required_amount_minor,
                (string) $deposit->currency_code
            );

            return [$deposit, null, (int) $snapshot->bidder_deposit_required_minor];
        }

        $settlement = $this->settlements->lockCurrentSettlementForPayment($auction->id);

        if (! $settlement) {
            throw new AuctionException(__('auction.errors.settlement_payment_unavailable'));
        }

        $this->eligibility->assertCanSubmitWinnerSettlement($auction, $settlement, $userId);

        return [null, $settlement, max(0, (int) ($settlement->remaining_amount_minor ?? ($settlement->amount_due_minor - $settlement->amount_paid_minor)))];
    }

    private function ensurePaymentSubmissionCanBeCreated($deposit, $settlement, string $obligationKey): void
    {
        if ($this->payments->lockSucceededTransactionForObligation($obligationKey)) {
            throw new AuctionException(__('auction.errors.payment_obligation_already_paid'));
        }

        if ($deposit) {
            if (in_array($deposit->status, [
                AuctionDepositStatus::Held,
                AuctionDepositStatus::AppliedToSettlement,
                AuctionDepositStatus::RefundPending,
                AuctionDepositStatus::Refunded,
                AuctionDepositStatus::Forfeited,
            ], true)) {
                throw new AuctionException(__('auction.errors.payment_obligation_already_paid'));
            }

            if ($this->payments->findPendingReviewSubmissionForDeposit($deposit->id)) {
                throw new AuctionException(__('auction.errors.active_payment_submission_exists'));
            }

            return;
        }

        if ((int) $settlement->remaining_amount_minor <= 0) {
            throw new AuctionException(__('auction.errors.payment_obligation_already_paid'));
        }

        if ($this->payments->findPendingReviewSubmissionForSettlement($settlement->id)) {
            throw new AuctionException(__('auction.errors.active_payment_submission_exists'));
        }
    }
}
