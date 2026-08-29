<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionSettlementRepository;

final class PaymentObligationResolver
{
    public function __construct(
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionSettlementRepository $settlements,
        private readonly PaymentEligibilityRule $eligibility,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
    ) {}

    public function resolve(Auction $auction, int $userId, PaymentPurpose $purpose): PaymentObligation
    {
        if ($purpose === PaymentPurpose::SellerDeposit) {
            $this->eligibility->assertCanSubmitSellerDeposit($auction, $userId);
        }

        $snapshot = $this->snapshotReader->forAuction($auction);

        if ($purpose === PaymentPurpose::SellerDeposit) {
            $this->deposits->firstOrCreateDeposit(
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

            return new PaymentObligation(
                $deposit,
                null,
                (int) $snapshot->seller_deposit_required_minor,
                (string) $snapshot->currency_code
            );
        }

        if ($purpose === PaymentPurpose::BidderDeposit) {
            $participant = $this->participants->lockParticipant($auction->id, $userId);

            if (! $participant) {
                throw AuctionException::domain('registration_required');
            }

            $this->eligibility->assertCanSubmitBidderDeposit($auction, $participant, $userId);

            $this->deposits->firstOrCreateDeposit(
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

            return new PaymentObligation(
                $deposit,
                null,
                (int) $snapshot->bidder_deposit_required_minor,
                (string) $snapshot->currency_code
            );
        }

        $settlement = $this->settlements->lockCurrentSettlementForPayment($auction->id);

        if (! $settlement) {
            throw AuctionException::domain('settlement_payment_unavailable');
        }

        $this->eligibility->assertCanSubmitWinnerSettlement($auction, $settlement, $userId);

        $remaining = max(0, (int) ($settlement->remaining_amount_minor ?? ($settlement->amount_due_minor - $settlement->amount_paid_minor)));

        return new PaymentObligation(null, $settlement, $remaining, (string) $settlement->currency_code);
    }
}
