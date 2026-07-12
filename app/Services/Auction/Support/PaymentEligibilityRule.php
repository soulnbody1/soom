<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentSubmission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class PaymentEligibilityRule
{
    public const OVERRIDE_DEADLINE_PERMISSION = 'auction.payment.override_deadline';

    private const PAYABLE_DEPOSIT_STATUSES = [
        AuctionDepositStatus::PendingSubmission,
        AuctionDepositStatus::PendingReview,
        AuctionDepositStatus::Rejected,
    ];

    private const PAID_DEPOSIT_STATUSES = [
        AuctionDepositStatus::Held,
        AuctionDepositStatus::AppliedToSettlement,
        AuctionDepositStatus::RefundPending,
        AuctionDepositStatus::Refunded,
        AuctionDepositStatus::Forfeited,
    ];

    public function assertCanSubmitSellerDeposit(Auction $auction, int $userId): void
    {
        if ($auction->status !== AuctionStatus::AwaitingSellerDeposit) {
            throw new AuctionException(__('auction.errors.seller_deposit_state_not_allowed'));
        }

        if ($auction->seller_id !== $userId) {
            throw new AuctionException(__('auction.errors.payment_target_owner_mismatch'));
        }

        if ((int) $auction->seller_deposit_amount_minor <= 0) {
            throw new AuctionException(__('auction.errors.zero_deposit_not_required'));
        }
    }

    public function assertCanSubmitBidderDeposit(Auction $auction, AuctionParticipant $participant, int $userId): void
    {
        $this->assertBidderDepositAuctionState($auction);
        $this->assertBidderDeadline($auction, false, null, null);

        if ($participant->auction_id !== $auction->id || $participant->user_id !== $userId) {
            throw new AuctionException(__('auction.errors.payment_target_owner_mismatch'));
        }

        if ($participant->status === AuctionParticipantStatus::Blocked) {
            throw new AuctionException(__('auction.errors.participant_not_eligible'));
        }

        if ((int) $auction->bidder_deposit_amount_minor <= 0) {
            throw new AuctionException(__('auction.errors.zero_deposit_not_required'));
        }
    }

    public function assertCanSubmitWinnerSettlement(Auction $auction, AuctionSettlement $settlement, int $userId): void
    {
        $this->assertWinnerSettlementAuctionState($auction);
        $this->assertSettlementTarget($auction, $settlement, $userId);
        $this->assertWinnerSettlementDeadline($settlement, false, null, null);
    }

    public function assertCanApproveSubmission(
        Auction $auction,
        PaymentSubmission $submission,
        ?AuctionDeposit $deposit,
        ?AuctionParticipant $participant,
        ?AuctionSettlement $settlement,
        bool $overrideDeadline,
        ?string $overrideReason,
        ?int $adminId
    ): ?CarbonInterface {
        if ($submission->status !== PaymentSubmissionStatus::PendingReview) {
            throw new AuctionException(__('auction.errors.payment_already_processed'));
        }

        return match ($submission->purpose) {
            PaymentPurpose::SellerDeposit => $this->assertSellerDepositApproval($auction, $submission, $deposit),
            PaymentPurpose::BidderDeposit => $this->assertBidderDepositApproval(
                $auction,
                $submission,
                $deposit,
                $participant,
                $overrideDeadline,
                $overrideReason,
                $adminId
            ),
            PaymentPurpose::WinnerSettlement => $this->assertWinnerSettlementApproval(
                $auction,
                $submission,
                $settlement,
                $overrideDeadline,
                $overrideReason,
                $adminId
            ),
        };
    }

    public function assertDepositTarget(
        Auction $auction,
        AuctionDeposit $deposit,
        int $userId,
        string $type,
        ?AuctionParticipant $participant = null
    ): void {
        if ($deposit->auction_id !== $auction->id || $deposit->type !== $type) {
            throw new AuctionException(__('auction.errors.payment_submission_obligation_mismatch'));
        }

        if ($deposit->user_id !== $userId) {
            throw new AuctionException(__('auction.errors.payment_target_owner_mismatch'));
        }

        if ($deposit->currency_code !== $auction->currency_code) {
            throw new AuctionException(__('auction.errors.payment_currency_mismatch'));
        }

        if ($participant && $deposit->participant_id !== $participant->id) {
            throw new AuctionException(__('auction.errors.payment_submission_obligation_mismatch'));
        }

        if (in_array($deposit->status, self::PAID_DEPOSIT_STATUSES, true)) {
            throw new AuctionException(__('auction.errors.payment_obligation_already_paid'));
        }

        if (! in_array($deposit->status, self::PAYABLE_DEPOSIT_STATUSES, true)) {
            throw new AuctionException(__('auction.errors.payment_submission_obligation_mismatch'));
        }
    }

    public function assertPaymentDetails(int $actualAmount, string $actualCurrency, int $requiredAmount, string $requiredCurrency): void
    {
        if ($actualAmount <= 0) {
            throw new AuctionException(__('auction.errors.zero_payment_not_allowed'));
        }

        if ($actualCurrency !== $requiredCurrency) {
            throw new AuctionException(__('auction.errors.payment_currency_mismatch'));
        }

        if ($actualAmount !== $requiredAmount) {
            throw new AuctionException(
                $actualAmount > $requiredAmount
                    ? __('auction.errors.payment_amount_exceeds_remaining')
                    : __('auction.errors.payment_amount_mismatch')
            );
        }
    }

    public function assertSettlementTarget(Auction $auction, AuctionSettlement $settlement, int $userId): void
    {
        if ($settlement->auction_id !== $auction->id || $settlement->winner_id !== $userId) {
            throw new AuctionException(__('auction.errors.payment_target_owner_mismatch'));
        }

        if (! $settlement->is_current || $settlement->current_marker !== 1) {
            throw new AuctionException(__('auction.errors.payment_target_not_current'));
        }

        if ($auction->winning_bid_id !== null && $auction->winning_bid_id !== $settlement->winning_bid_id) {
            throw new AuctionException(__('auction.errors.winner_changed'));
        }

        if ($settlement->status !== SettlementStatus::PaymentPending) {
            if ($settlement->status === SettlementStatus::Paid) {
                throw new AuctionException(__('auction.errors.payment_obligation_already_paid'));
            }

            throw new AuctionException(__('auction.errors.payment_target_not_current'));
        }

        if ((int) $settlement->remaining_amount_minor <= 0) {
            throw new AuctionException(__('auction.errors.payment_obligation_already_paid'));
        }

        if ($settlement->currency_code !== $auction->currency_code) {
            throw new AuctionException(__('auction.errors.payment_currency_mismatch'));
        }
    }

    private function assertSellerDepositApproval(Auction $auction, PaymentSubmission $submission, ?AuctionDeposit $deposit): ?CarbonInterface
    {
        if (! $deposit) {
            throw new AuctionException(__('auction.errors.payment_submission_obligation_mismatch'));
        }

        $this->assertDepositTarget($auction, $deposit, (int) $submission->user_id, 'seller');
        $this->assertPaymentDetails(
            (int) $submission->amount_minor,
            (string) $submission->currency_code,
            (int) $deposit->required_amount_minor,
            (string) $deposit->currency_code
        );

        if (in_array($auction->status, [AuctionStatus::Cancelled, AuctionStatus::Rejected], true)) {
            throw new AuctionException(__('auction.errors.payment_approval_auction_not_active'));
        }

        $this->assertCanSubmitSellerDeposit($auction, (int) $submission->user_id);

        return null;
    }

    private function assertBidderDepositApproval(
        Auction $auction,
        PaymentSubmission $submission,
        ?AuctionDeposit $deposit,
        ?AuctionParticipant $participant,
        bool $overrideDeadline,
        ?string $overrideReason,
        ?int $adminId
    ): ?CarbonInterface {
        if (! $deposit || ! $participant) {
            throw new AuctionException(__('auction.errors.payment_submission_obligation_mismatch'));
        }

        $this->assertBidderDepositAuctionState($auction);

        if ($participant->status === AuctionParticipantStatus::Blocked) {
            throw new AuctionException(__('auction.errors.participant_not_eligible'));
        }

        if ($participant->auction_id !== $auction->id || $participant->user_id !== $submission->user_id) {
            throw new AuctionException(__('auction.errors.payment_target_owner_mismatch'));
        }

        $this->assertDepositTarget($auction, $deposit, (int) $submission->user_id, 'bidder', $participant);
        $this->assertPaymentDetails(
            (int) $submission->amount_minor,
            (string) $submission->currency_code,
            (int) $deposit->required_amount_minor,
            (string) $deposit->currency_code
        );

        return $this->assertBidderDeadline($auction, $overrideDeadline, $overrideReason, $adminId);
    }

    private function assertWinnerSettlementApproval(
        Auction $auction,
        PaymentSubmission $submission,
        ?AuctionSettlement $settlement,
        bool $overrideDeadline,
        ?string $overrideReason,
        ?int $adminId
    ): ?CarbonInterface {
        if (! $settlement) {
            throw new AuctionException(__('auction.errors.payment_submission_obligation_mismatch'));
        }

        $this->assertSettlementTarget($auction, $settlement, (int) $submission->user_id);
        $this->assertWinnerSettlementAuctionState($auction);
        $this->assertPaymentDetails(
            (int) $submission->amount_minor,
            (string) $submission->currency_code,
            (int) $settlement->remaining_amount_minor,
            (string) $settlement->currency_code
        );

        return $this->assertWinnerSettlementDeadline($settlement, $overrideDeadline, $overrideReason, $adminId);
    }

    private function assertBidderDepositAuctionState(Auction $auction): void
    {
        if (! in_array($auction->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true)) {
            throw new AuctionException(__('auction.errors.bidder_deposit_state_not_allowed'));
        }
    }

    private function assertWinnerSettlementAuctionState(Auction $auction): void
    {
        if ($auction->status !== AuctionStatus::PaymentPending) {
            throw new AuctionException(__('auction.errors.winner_payment_state_not_allowed'));
        }
    }

    private function assertBidderDeadline(
        Auction $auction,
        bool $overrideDeadline,
        ?string $overrideReason,
        ?int $adminId
    ): ?CarbonInterface {
        if (! $auction->ends_at || Carbon::now()->lessThanOrEqualTo($auction->ends_at)) {
            return null;
        }

        return $this->assertDeadlineOverrideAllowed($auction->ends_at, $overrideDeadline, $overrideReason, $adminId);
    }

    private function assertWinnerSettlementDeadline(
        AuctionSettlement $settlement,
        bool $overrideDeadline,
        ?string $overrideReason,
        ?int $adminId
    ): ?CarbonInterface {
        if (! $settlement->payment_due_at || Carbon::now()->lessThanOrEqualTo($settlement->payment_due_at)) {
            return null;
        }

        return $this->assertDeadlineOverrideAllowed($settlement->payment_due_at, $overrideDeadline, $overrideReason, $adminId);
    }

    private function assertDeadlineOverrideAllowed(
        CarbonInterface $deadline,
        bool $overrideDeadline,
        ?string $overrideReason,
        ?int $adminId
    ): CarbonInterface {
        if (! $overrideDeadline) {
            throw new AuctionException(__('auction.errors.payment_deadline_expired'));
        }

        if (trim((string) $overrideReason) === '') {
            throw new AuctionException(__('auction.errors.payment_override_reason_required'));
        }

        if (! $adminId || ! $this->userHasPermission($adminId, self::OVERRIDE_DEADLINE_PERMISSION)) {
            throw new AuctionException(__('auction.errors.payment_override_not_authorized'));
        }

        return $deadline;
    }

    private function userHasPermission(int $userId, string $permission): bool
    {
        $user = User::find($userId);

        if (! $user) {
            return false;
        }

        $explicit = $user->getAttribute('auction_permissions');

        if (is_string($explicit)) {
            $decoded = json_decode($explicit, true);
            $explicit = is_array($decoded) ? $decoded : [];
        }

        if (is_array($explicit) && in_array($permission, $explicit, true)) {
            return true;
        }

        return $user->role === 'admin'
            && in_array($permission, config('auction.admin_permissions', []), true);
    }
}
