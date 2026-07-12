<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\OutboxMessage;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\CancelAuctionAction;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use App\Services\Auction\Actions\MarkWinnerDefaultedAction;
use App\Services\Auction\Actions\PlanNonWinnerDepositRefundsAction;
use App\Services\Auction\Actions\ReconcileAuctionsAction;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NonWinnerDepositReleaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_immediate_policy_plans_one_refund_for_paid_non_winners_only(): void
    {
        [$auction, $bids] = $this->auctionWithBids('refund_all_non_winners_immediately', [100_000, 90_000, 80_000, 70_000]);
        $unpaid = $this->participantWithDeposit($auction, paid: false, amount: 10_000);

        app(FinalizeAuctionAction::class)->execute($auction);

        $winnerDeposit = AuctionDeposit::where('user_id', $bids[0]->bidder_id)->firstOrFail();
        $this->assertSame(0, RefundTransaction::where('deposit_id', $winnerDeposit->id)->count());

        $this->assertSame(3, RefundTransaction::where('auction_id', $auction->id)->count());
        $this->assertSame(1, RefundTransaction::where('deposit_id', AuctionDeposit::where('user_id', $bids[1]->bidder_id)->value('id'))->count());
        $this->assertSame(AuctionDepositStatus::Rejected, $unpaid['deposit']->refresh()->status);
        $this->assertSame(0, RefundTransaction::where('deposit_id', $unpaid['deposit']->id)->count());
    }

    public function test_hold_all_policy_keeps_eligible_until_winner_payment_then_releases(): void
    {
        [$auction, $bids] = $this->auctionWithBids('hold_all_eligible_bidders_until_winner_payment', [100_000, 90_000, 80_000]);
        $blocked = $this->participantWithDeposit($auction, paid: true, amount: 10_000, status: AuctionParticipantStatus::Blocked, acceptTerms: false);
        $this->bid($auction, $blocked['participant'], $blocked['user'], 70_000, 4);

        $auction = app(FinalizeAuctionAction::class)->execute($auction);

        foreach ([$bids[1], $bids[2]] as $bid) {
            $deposit = AuctionDeposit::where('user_id', $bid->bidder_id)->firstOrFail();
            $this->assertSame(AuctionDepositStatus::Held, $deposit->status);
            $this->assertSame('alternative_winner_candidate', $deposit->hold_reason);
        }
        $this->assertSame(1, RefundTransaction::where('deposit_id', $blocked['deposit']->id)->count());

        $this->approveWinnerPayment($auction->refresh());

        foreach ([$bids[1], $bids[2]] as $bid) {
            $deposit = AuctionDeposit::where('user_id', $bid->bidder_id)->firstOrFail();
            $this->assertSame(AuctionDepositStatus::RefundPending, $deposit->status);
            $this->assertNull($deposit->hold_reason);
            $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->count());
        }
        $this->assertSame(0, RefundTransaction::where('deposit_id', AuctionDeposit::where('user_id', $bids[0]->bidder_id)->value('id'))->count());
    }

    public function test_hold_top_n_policy_holds_ranked_candidates_and_releases_after_payment(): void
    {
        [$auction, $bids] = $this->auctionWithBids('hold_top_n_bidders_until_winner_payment', [100_000, 90_000, 80_000, 70_000], holdCount: 2);

        $auction = app(FinalizeAuctionAction::class)->execute($auction);

        foreach ([1, 2] as $index) {
            $deposit = AuctionDeposit::where('user_id', $bids[$index]->bidder_id)->firstOrFail();
            $this->assertSame(AuctionDepositStatus::Held, $deposit->status);
            $this->assertSame($index, $deposit->hold_metadata['candidate_rank']);
        }

        $fourthDeposit = AuctionDeposit::where('user_id', $bids[3]->bidder_id)->firstOrFail();
        $this->assertSame(AuctionDepositStatus::RefundPending, $fourthDeposit->status);
        $this->assertSame(1, RefundTransaction::where('deposit_id', $fourthDeposit->id)->count());

        $this->approveWinnerPayment($auction->refresh());

        foreach ([1, 2] as $index) {
            $deposit = AuctionDeposit::where('user_id', $bids[$index]->bidder_id)->firstOrFail();
            $this->assertSame(AuctionDepositStatus::RefundPending, $deposit->status);
            $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->count());
        }
    }

    public function test_winner_default_with_alternative_keeps_new_winner_and_next_candidate_only(): void
    {
        [$auction, $bids] = $this->auctionWithBids('hold_top_n_bidders_until_winner_payment', [100_000, 90_000, 80_000, 70_000], holdCount: 2);
        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $auction->settlement->forceFill(['payment_due_at' => now()->subMinute()])->save();

        app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $this->user('admin')->id, 'deadline expired', true);

        $defaultedDeposit = AuctionDeposit::where('user_id', $bids[0]->bidder_id)->firstOrFail();
        $newWinnerDeposit = AuctionDeposit::where('user_id', $bids[1]->bidder_id)->firstOrFail();
        $nextCandidateDeposit = AuctionDeposit::where('user_id', $bids[2]->bidder_id)->firstOrFail();
        $releasedDeposit = AuctionDeposit::where('user_id', $bids[3]->bidder_id)->firstOrFail();

        $this->assertSame(AuctionDepositStatus::AppliedToSettlement, $defaultedDeposit->status);
        $this->assertSame(0, RefundTransaction::where('deposit_id', $defaultedDeposit->id)->count());
        $this->assertSame(0, RefundTransaction::where('deposit_id', $newWinnerDeposit->id)->count());
        $this->assertSame(AuctionDepositStatus::Held, $nextCandidateDeposit->status);
        $this->assertSame('alternative_winner_candidate', $nextCandidateDeposit->hold_reason);
        $this->assertSame(1, RefundTransaction::where('deposit_id', $releasedDeposit->id)->count());
    }

    public function test_winner_default_without_alternative_releases_remaining_refundable_deposits(): void
    {
        [$auction, $bids] = $this->auctionWithBids('hold_all_eligible_bidders_until_winner_payment', [100_000, 90_000]);
        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $auction->settlement->forceFill(['payment_due_at' => now()->subMinute()])->save();

        app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $this->user('admin')->id, 'deadline expired', false);

        $nonWinnerDeposit = AuctionDeposit::where('user_id', $bids[1]->bidder_id)->firstOrFail();
        $this->assertSame(AuctionDepositStatus::RefundPending, $nonWinnerDeposit->status);
        $this->assertSame(1, RefundTransaction::where('deposit_id', $nonWinnerDeposit->id)->count());
    }

    public function test_unsold_release_refunds_paid_bidder_deposits(): void
    {
        [$auction, $bids] = $this->auctionWithBids('hold_all_eligible_bidders_until_winner_payment', [100_000, 90_000]);
        $auction->forceFill(['reserve_amount_minor' => 150_000])->save();

        app(FinalizeAuctionAction::class)->execute($auction);

        foreach ($bids as $bid) {
            $deposit = AuctionDeposit::where('user_id', $bid->bidder_id)->firstOrFail();
            $this->assertSame(AuctionDepositStatus::RefundPending, $deposit->status);
            $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->count());
        }
    }

    public function test_completed_cleanup_releases_stale_non_winner_without_touching_current_winner(): void
    {
        [$auction, $bids] = $this->auctionWithBids('hold_all_eligible_bidders_until_winner_payment', [100_000, 90_000]);
        $this->currentSettlement($auction, $bids[0], SettlementStatus::Completed);
        $auction->forceFill(['status' => AuctionStatus::Completed, 'winning_bid_id' => $bids[0]->id])->save();

        app(PlanNonWinnerDepositRefundsAction::class)->execute($auction, 'completed');

        $winnerDeposit = AuctionDeposit::where('user_id', $bids[0]->bidder_id)->firstOrFail();
        $nonWinnerDeposit = AuctionDeposit::where('user_id', $bids[1]->bidder_id)->firstOrFail();
        $this->assertSame(0, RefundTransaction::where('deposit_id', $winnerDeposit->id)->count());
        $this->assertSame(1, RefundTransaction::where('deposit_id', $nonWinnerDeposit->id)->count());
    }

    public function test_cancelled_auction_does_not_duplicate_existing_cancellation_refund(): void
    {
        [$auction, $bids] = $this->auctionWithBids('hold_all_eligible_bidders_until_winner_payment', [100_000, 90_000]);
        app(CancelAuctionAction::class)->execute($auction, $this->user('admin')->id, 'admin', 'cancelled');

        app(PlanNonWinnerDepositRefundsAction::class)->execute($auction->refresh(), 'cancelled');

        foreach ($bids as $bid) {
            $deposit = AuctionDeposit::where('user_id', $bid->bidder_id)->firstOrFail();
            $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->count());
        }
    }

    public function test_release_action_is_idempotent_for_refunds_audit_and_outbox(): void
    {
        [$auction, $bids] = $this->auctionWithBids('refund_all_non_winners_immediately', [100_000, 90_000]);
        $this->currentSettlement($auction, $bids[0], SettlementStatus::Paid);
        $auction->forceFill(['status' => AuctionStatus::HandoverPending, 'winning_bid_id' => $bids[0]->id])->save();

        $action = app(PlanNonWinnerDepositRefundsAction::class);
        $action->execute($auction, 'winner_payment');
        $action->execute($auction->refresh(), 'winner_payment');

        $deposit = AuctionDeposit::where('user_id', $bids[1]->bidder_id)->firstOrFail();
        $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)->where('event_type', 'auction.non_winner_deposit_refund_planned')->count());
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)->where('event_type', 'auction.non_winner_deposit_refund_planned')->count());
    }

    public function test_active_refund_and_unpaid_deposit_are_not_refunded_again(): void
    {
        [$auction, $bids] = $this->auctionWithBids('refund_all_non_winners_immediately', [100_000, 90_000]);
        $paidDeposit = AuctionDeposit::where('user_id', $bids[1]->bidder_id)->firstOrFail();
        $payment = PaymentTransaction::where('successful_obligation_key', "deposit:{$paidDeposit->id}")->firstOrFail();
        $this->refund($auction, $paidDeposit, $payment);
        $unpaid = $this->participantWithDeposit($auction, paid: false, amount: 10_000);

        app(PlanNonWinnerDepositRefundsAction::class)->execute($auction, 'unsold');

        $this->assertSame(1, RefundTransaction::where('deposit_id', $paidDeposit->id)->count());
        $this->assertSame(0, RefundTransaction::where('deposit_id', $unpaid['deposit']->id)->count());
        $this->assertSame(AuctionDepositStatus::Rejected, $unpaid['deposit']->refresh()->status);
    }

    public function test_reconciliation_detects_terminal_stale_held_non_winner_deposit(): void
    {
        [$auction, $bids] = $this->auctionWithBids('hold_all_eligible_bidders_until_winner_payment', [100_000, 90_000]);
        $this->currentSettlement($auction, $bids[0], SettlementStatus::Completed);
        $auction->forceFill(['status' => AuctionStatus::Completed, 'winning_bid_id' => $bids[0]->id])->save();

        $report = app(ReconcileAuctionsAction::class)->execute();

        $this->assertSame(1, $report['terminal_non_winner_deposits_held_without_active_need']);
    }

    private function auctionWithBids(string $policy, array $amounts, int $holdCount = 1): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $configuration = AuctionConfigurationVersion::create([
            'version_number' => ((int) AuctionConfigurationVersion::max('version_number')) + 1,
            'configuration' => [
                'non_winner_deposit_policy' => $policy,
                'non_winner_deposit_hold_count' => $holdCount,
            ],
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'non-winner-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'non-winner-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Non winner deposit auction',
            'description' => 'Non winner deposit auction.',
            'status' => AuctionStatus::Ended,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 10_000,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->subDays(2),
            'original_ends_at' => now()->subHour(),
            'ends_at' => now()->subHour(),
        ]);

        $bids = [];
        foreach ($amounts as $index => $amount) {
            $entry = $this->participantWithDeposit($auction, paid: true, amount: 10_000);
            $bids[] = $this->bid($auction, $entry['participant'], $entry['user'], $amount, $index + 1);
        }

        return [$auction->refresh(), $bids];
    }

    private function participantWithDeposit(
        Auction $auction,
        bool $paid,
        int $amount,
        AuctionParticipantStatus $status = AuctionParticipantStatus::Qualified,
        bool $acceptTerms = true,
    ): array {
        $user = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => $status,
            'registered_at' => now()->subDay(),
            'qualified_at' => $status === AuctionParticipantStatus::Qualified ? now()->subHour() : null,
            'blocked_at' => $status === AuctionParticipantStatus::Blocked ? now()->subHour() : null,
            'block_reason' => $status === AuctionParticipantStatus::Blocked ? 'blocked' : null,
        ]);
        if ($acceptTerms) {
            AuctionTermsAcceptance::create([
                'auction_id' => $auction->id,
                'participant_id' => $participant->id,
                'user_id' => $user->id,
                'terms_version_id' => $auction->terms_version_id,
                'accepted_at' => now()->subHour(),
            ]);
        }
        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $amount,
            'held_amount_minor' => $amount,
            'currency_code' => 'JOD',
            'held_at' => now()->subHour(),
        ]);
        if ($paid) {
            $this->paymentForDeposit($auction, $deposit, $user, $amount);
        }

        return ['user' => $user, 'participant' => $participant, 'deposit' => $deposit];
    }

    private function bid(Auction $auction, AuctionParticipant $participant, User $user, int $amount, int $sequence): AuctionBid
    {
        return AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'sequence_number' => $sequence,
            'idempotency_key' => 'non-winner-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);
    }

    private function approveWinnerPayment(Auction $auction): void
    {
        $settlement = $auction->settlement()->firstOrFail();
        if ($settlement->remaining_amount_minor <= 0) {
            return;
        }

        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $settlement->winner_id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $settlement->remaining_amount_minor,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'settlement.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'winner-payment-'.Str::ulid(),
            'submitted_at' => now(),
        ]);

        app(ReviewPaymentSubmissionAction::class)->approve($submission, $this->user('admin')->id, 'approved');
    }

    private function currentSettlement(Auction $auction, AuctionBid $bid, SettlementStatus $status): AuctionSettlement
    {
        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => $status,
            'winning_amount_minor' => $bid->amount_minor,
            'deposit_applied_minor' => 10_000,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => max(0, $bid->amount_minor - 2_500),
            'amount_due_minor' => 90_000,
            'amount_paid_minor' => $status === SettlementStatus::Paid || $status === SettlementStatus::Completed ? 90_000 : 0,
            'remaining_amount_minor' => $status === SettlementStatus::Paid || $status === SettlementStatus::Completed ? 0 : 90_000,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->addDay(),
            'paid_at' => $status === SettlementStatus::Paid || $status === SettlementStatus::Completed ? now()->subMinute() : null,
        ]);
    }

    private function paymentForDeposit(Auction $auction, AuctionDeposit $deposit, User $user, int $amount): PaymentTransaction
    {
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $user->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'deposit-'.Str::ulid(),
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now()->subHour(),
        ]);

        return PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'provider' => 'manual',
            'provider_transaction_id' => 'provider-'.Str::ulid(),
            'idempotency_key' => 'payment-'.Str::ulid(),
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => now()->subHour(),
        ]);
    }

    private function refund(Auction $auction, AuctionDeposit $deposit, PaymentTransaction $payment): RefundTransaction
    {
        return RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'payment_transaction_id' => $payment->id,
            'obligation_type' => 'deposit',
            'obligation_id' => $deposit->id,
            'user_id' => $deposit->user_id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => 10_000,
            'held_refund_amount_minor' => 10_000,
            'applied_refund_amount_minor' => 0,
            'currency_code' => 'JOD',
            'provider' => 'manual',
            'idempotency_key' => 'active-refund-'.Str::ulid(),
            'reason' => 'existing active refund',
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Manual transfer',
            'code' => 'non-winner-'.Str::ulid(),
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "non-winner-{$unique}@example.test",
            'phone' => '+96274'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
