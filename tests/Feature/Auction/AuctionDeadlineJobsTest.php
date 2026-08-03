<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Jobs\Auction\AutoDefaultOverdueWinnersJob;
use App\Jobs\Auction\ExpireSellerDepositDeadlinesJob;
use App\Jobs\Auction\SendHandoverRemindersJob;
use App\Jobs\Auction\SendWinnerPaymentRemindersJob;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\OutboxMessage;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsAuctionDeadlineFixtures;
use Tests\TestCase;

final class AuctionDeadlineJobsTest extends TestCase
{
    use BuildsAuctionDeadlineFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_settlement_freezes_payment_grace_end(): void
    {
        [$auction, $settlement] = $this->settledAuction();

        $this->assertNotNull($settlement->payment_due_at);
        $this->assertNotNull($settlement->payment_grace_ends_at);
        $this->assertSame(
            (int) config('auction.deadlines.winner_payment_grace_period_hours') * 60,
            (int) $settlement->payment_due_at->diffInMinutes($settlement->payment_grace_ends_at)
        );
        $this->assertSame(AuctionStatus::PaymentPending, $auction->refresh()->status);
    }

    public function test_payment_reminders_fire_at_each_frozen_offset_without_repeating(): void
    {
        [, $settlement] = $this->settledAuction();
        $dueAt = $settlement->payment_due_at;

        $this->travelTo($dueAt->copy()->subHours(23));
        app(SendWinnerPaymentRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));
        $this->assertSame([24], $this->remindersSent($settlement));
        $this->assertSame(1, $this->reminderMessages($settlement->auction_id));

        app(SendWinnerPaymentRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));
        $this->assertSame(1, $this->reminderMessages($settlement->auction_id));

        $this->travelTo($dueAt->copy()->subHours(5));
        app(SendWinnerPaymentRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));
        $this->assertSame([24, 6], $this->remindersSent($settlement));

        $this->travelTo($dueAt->copy()->subMinutes(30));
        app(SendWinnerPaymentRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));
        $this->assertSame([24, 6, 1], $this->remindersSent($settlement));
        $this->assertSame(3, $this->reminderMessages($settlement->auction_id));
    }

    public function test_payment_reminders_use_the_frozen_snapshot_not_current_config(): void
    {
        [, $settlement] = $this->settledAuction();
        $dueAt = $settlement->payment_due_at;

        config(['auction.deadlines.winner_payment_reminder_hours_before' => [48]]);

        $this->travelTo($dueAt->copy()->subHours(23));
        app(SendWinnerPaymentRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));

        $this->assertSame([24], $this->remindersSent($settlement));
    }

    public function test_missed_offsets_collapse_into_a_single_catch_up_reminder(): void
    {
        [$auction, $settlement] = $this->settledAuction();
        $dueAt = $settlement->payment_due_at;

        $this->travelTo($dueAt->copy()->subHours(5));
        app(SendWinnerPaymentRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));

        $this->assertSame([24, 6], $this->remindersSent($settlement));
        $this->assertSame(1, $this->reminderMessages($auction->id));

        $message = OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.winner_payment_reminder')
            ->first();
        $this->assertSame(6, $message->payload['hours_before']);
    }

    public function test_deadline_passing_sends_one_overdue_notice_instead_of_silent_loss(): void
    {
        [$auction, $settlement] = $this->settledAuction();

        $this->travelTo($settlement->payment_due_at->copy()->addMinute());
        $job = app(SendWinnerPaymentRemindersJob::class);
        $job->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));

        $this->assertContains(0, $this->remindersSent($settlement));
        $this->assertSame(1, $this->reminderMessages($auction->id));

        $message = OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.winner_payment_reminder')
            ->first();
        $this->assertSame(0, $message->payload['hours_before']);

        $job->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));
        $this->assertSame(1, $this->reminderMessages($auction->id));
    }

    public function test_no_reminder_is_sent_once_the_grace_period_has_expired(): void
    {
        [$auction, $settlement] = $this->settledAuction();

        $this->travelTo($settlement->payment_grace_ends_at->copy()->addMinute());
        app(SendWinnerPaymentRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));

        $this->assertSame(0, $this->reminderMessages($auction->id));
    }

    public function test_no_reminder_is_sent_once_the_winner_has_paid(): void
    {
        [$auction, $settlement] = $this->settledAuction();
        $settlement->forceFill(['status' => SettlementStatus::Paid])->save();

        $this->travelTo($settlement->payment_due_at->copy()->addMinute());
        app(SendWinnerPaymentRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));

        $this->assertSame(0, $this->reminderMessages($auction->id));
    }

    public function test_overdue_handover_sends_one_notice_without_closing_the_deal(): void
    {
        [$auction, $settlement] = $this->handoverPendingAuction();

        $this->travelTo($settlement->handover_due_at->copy()->addDay());
        $job = app(SendHandoverRemindersJob::class);
        $job->handle(app(\App\Services\Auction\Actions\SendHandoverRemindersAction::class));
        $job->handle(app(\App\Services\Auction\Actions\SendHandoverRemindersAction::class));

        $messages = OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.handover_reminder')
            ->get();

        $this->assertCount(1, $messages);
        $this->assertSame(0, $messages->first()->payload['hours_before']);
        $this->assertSame(AuctionStatus::HandoverPending, $auction->refresh()->status);
        $this->assertNull($settlement->refresh()->handover_completed_at);
    }

    public function test_seller_deposit_expiry_retries_after_a_crashed_claim(): void
    {
        [$auction] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $auction->forceFill([
            'seller_deposit_due_at' => Carbon::now()->subHours(2),
            'seller_deposit_deadline_processed_at' => Carbon::now()->subHours(2),
        ])->save();

        app(ExpireSellerDepositDeadlinesJob::class)->handle(app(\App\Services\Auction\Actions\ExpireSellerDepositDeadlineAction::class));

        $this->assertSame(AuctionStatus::Cancelled, $auction->refresh()->status);
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.seller_deposit_expired')
            ->count());
    }

    public function test_seller_deposit_expiry_respects_an_active_claim_lease(): void
    {
        [$auction] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $auction->forceFill([
            'seller_deposit_due_at' => Carbon::now()->subHours(2),
            'seller_deposit_deadline_processed_at' => Carbon::now(),
        ])->save();

        $cancelled = app(\App\Services\Auction\Actions\ExpireSellerDepositDeadlineAction::class)->execute($auction);

        $this->assertFalse($cancelled);
        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);
    }

    public function test_auto_default_waits_for_the_grace_period(): void
    {
        [$auction, $settlement] = $this->settledAuction();

        $this->travelTo($settlement->payment_due_at->copy()->addMinute());
        app(AutoDefaultOverdueWinnersJob::class)->handle(app(\App\Services\Auction\Actions\MarkWinnerDefaultedAction::class));

        $this->assertSame(AuctionStatus::PaymentPending, $auction->refresh()->status);
        $this->assertSame(SettlementStatus::PaymentPending, $settlement->refresh()->status);
    }

    public function test_auto_default_promotes_the_alternative_winner_after_grace(): void
    {
        [$auction, $settlement, $winner, $runnerUp] = $this->settledAuctionWithRunnerUp();

        $this->travelTo($settlement->payment_grace_ends_at->copy()->addMinute());
        app(AutoDefaultOverdueWinnersJob::class)->handle(app(\App\Services\Auction\Actions\MarkWinnerDefaultedAction::class));

        $settlement->refresh();
        $this->assertSame(SettlementStatus::Defaulted, $settlement->status);
        $this->assertTrue((bool) $settlement->auto_defaulted);
        $this->assertSame(AutoDefaultOverdueWinnersJob::REASON, $settlement->default_reason);

        $current = AuctionSettlement::where('auction_id', $auction->id)->where('is_current', true)->first();
        $this->assertNotNull($current);
        $this->assertSame($runnerUp->id, (int) $current->winner_id);
        $this->assertNotSame($winner->id, (int) $current->winner_id);
        $this->assertNotNull($current->payment_grace_ends_at);
    }

    public function test_auto_default_without_alternative_ends_unsold(): void
    {
        [$auction, $settlement] = $this->settledAuction();

        $this->travelTo($settlement->payment_grace_ends_at->copy()->addMinute());
        app(AutoDefaultOverdueWinnersJob::class)->handle(app(\App\Services\Auction\Actions\MarkWinnerDefaultedAction::class));

        $this->assertSame(AuctionStatus::Unsold, $auction->refresh()->status);
        $this->assertSame(0, AuctionSettlement::where('auction_id', $auction->id)->where('is_current', true)->count());
    }

    public function test_auto_default_is_idempotent_across_runs(): void
    {
        [$auction, $settlement] = $this->settledAuction();

        $this->travelTo($settlement->payment_grace_ends_at->copy()->addMinute());
        $job = app(AutoDefaultOverdueWinnersJob::class);
        $job->handle(app(\App\Services\Auction\Actions\MarkWinnerDefaultedAction::class));
        $job->handle(app(\App\Services\Auction\Actions\MarkWinnerDefaultedAction::class));

        $this->assertSame(AuctionStatus::Unsold, $auction->refresh()->status);
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.winner_defaulted')
            ->count());
    }

    public function test_auto_default_is_blocked_by_a_payment_submission_under_review(): void
    {
        [$auction, $settlement, $winner] = $this->settledAuctionWithRunnerUp();
        $this->pendingSettlementSubmission($auction, $settlement->id, $winner->id, (int) $settlement->amount_due_minor);

        $this->travelTo($settlement->payment_grace_ends_at->copy()->addMinute());
        app(AutoDefaultOverdueWinnersJob::class)->handle(app(\App\Services\Auction\Actions\MarkWinnerDefaultedAction::class));

        $this->assertSame(AuctionStatus::PaymentPending, $auction->refresh()->status);
        $this->assertSame(SettlementStatus::PaymentPending, $settlement->refresh()->status);
    }

    public function test_auto_default_action_refuses_when_submission_appears_after_selection(): void
    {
        [$auction, $settlement, $winner] = $this->settledAuctionWithRunnerUp();

        $this->travelTo($settlement->payment_grace_ends_at->copy()->addMinute());
        $this->pendingSettlementSubmission($auction, $settlement->id, $winner->id, (int) $settlement->amount_due_minor);

        $this->expectException(\App\Domain\Auction\Exceptions\AuctionException::class);

        app(\App\Services\Auction\Actions\MarkWinnerDefaultedAction::class)->execute(
            auction: $auction->refresh(),
            adminId: null,
            reason: AutoDefaultOverdueWinnersJob::REASON,
            reassignToNext: true,
            automatic: true,
        );
    }

    public function test_seller_deposit_deadline_cancels_unfunded_auction(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $auction->forceFill(['seller_deposit_due_at' => Carbon::now()->subMinute()])->save();

        app(ExpireSellerDepositDeadlinesJob::class)->handle(app(\App\Services\Auction\Actions\ExpireSellerDepositDeadlineAction::class));

        $auction->refresh();
        $this->assertSame(AuctionStatus::Cancelled, $auction->status);
        $this->assertNull($auction->published_at);
        $this->assertNotNull($auction->seller_deposit_deadline_processed_at);
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.seller_deposit_expired')
            ->count());
    }

    public function test_seller_deposit_deadline_is_idempotent(): void
    {
        [$auction] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $auction->forceFill(['seller_deposit_due_at' => Carbon::now()->subMinute()])->save();

        $job = app(ExpireSellerDepositDeadlinesJob::class);
        $job->handle(app(\App\Services\Auction\Actions\ExpireSellerDepositDeadlineAction::class));
        $job->handle(app(\App\Services\Auction\Actions\ExpireSellerDepositDeadlineAction::class));

        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.seller_deposit_expired')
            ->count());
    }

    public function test_seller_deposit_deadline_spares_auction_with_submission_under_review(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $auction->forceFill(['seller_deposit_due_at' => Carbon::now()->subMinute()])->save();
        $this->pendingSellerDepositSubmission($auction, $seller->id);

        app(ExpireSellerDepositDeadlinesJob::class)->handle(app(\App\Services\Auction\Actions\ExpireSellerDepositDeadlineAction::class));

        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);
    }

    public function test_seller_deposit_deadline_ignores_auction_before_due_date(): void
    {
        [$auction] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $auction->forceFill(['seller_deposit_due_at' => Carbon::now()->addHour()])->save();

        app(ExpireSellerDepositDeadlinesJob::class)->handle(app(\App\Services\Auction\Actions\ExpireSellerDepositDeadlineAction::class));

        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);
    }

    public function test_handover_reminders_target_the_acting_party_without_repeating(): void
    {
        [$auction, $settlement] = $this->handoverPendingAuction();
        $dueAt = $settlement->handover_due_at;

        $this->travelTo($dueAt->copy()->subHours(23));
        app(SendHandoverRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendHandoverRemindersAction::class));

        $message = OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.handover_reminder')
            ->first();
        $this->assertNotNull($message);
        $this->assertSame('seller', $message->payload['audience']);
        $this->assertSame([24], (array) ($settlement->refresh()->handover_reminders_sent['seller'] ?? []));

        app(SendHandoverRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendHandoverRemindersAction::class));
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.handover_reminder')
            ->count());

        $this->travelTo($dueAt->copy()->subMinutes(30));
        app(SendHandoverRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendHandoverRemindersAction::class));
        $this->assertSame([24, 1], (array) ($settlement->refresh()->handover_reminders_sent['seller'] ?? []));
    }

    public function test_handover_reminder_switches_to_winner_after_seller_confirms(): void
    {
        [$auction, $settlement] = $this->handoverPendingAuction();
        $settlement->forceFill(['seller_handover_confirmed_at' => Carbon::now()])->save();

        $this->travelTo($settlement->handover_due_at->copy()->subHours(23));
        app(SendHandoverRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendHandoverRemindersAction::class));

        $message = OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.handover_reminder')
            ->first();

        $this->assertNotNull($message);
        $this->assertSame('winner', $message->payload['audience']);
    }

    public function test_overdue_handover_is_not_auto_closed_or_forfeited(): void
    {
        [$auction, $settlement] = $this->handoverPendingAuction();

        $this->travelTo($settlement->handover_due_at->copy()->addDays(3));
        app(SendHandoverRemindersJob::class)->handle(app(\App\Services\Auction\Actions\SendHandoverRemindersAction::class));
        app(AutoDefaultOverdueWinnersJob::class)->handle(app(\App\Services\Auction\Actions\MarkWinnerDefaultedAction::class));

        $this->assertSame(AuctionStatus::HandoverPending, $auction->refresh()->status);
        $this->assertNull($settlement->refresh()->handover_completed_at);
    }

    private function settledAuction(): array
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [, $participant] = $this->qualifiedParticipant($auction, 10_000);
        $bid = $this->bid($auction, $participant, $participant->user, 100_000, 1);

        app(FinalizeAuctionAction::class)->execute($auction->refresh());

        $settlement = AuctionSettlement::where('auction_id', $auction->id)->where('is_current', true)->firstOrFail();

        return [$auction->refresh(), $settlement, $bid];
    }

    private function settledAuctionWithRunnerUp(): array
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [$runnerUp, $runnerUpParticipant] = $this->qualifiedParticipant($auction, 10_000);
        [$winner, $winnerParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $runnerUpParticipant, $runnerUp, 90_000, 1);
        $this->bid($auction, $winnerParticipant, $winner, 100_000, 2);

        app(FinalizeAuctionAction::class)->execute($auction->refresh());

        $settlement = AuctionSettlement::where('auction_id', $auction->id)->where('is_current', true)->firstOrFail();

        return [$auction->refresh(), $settlement, $winner, $runnerUp];
    }

    private function handoverPendingAuction(): array
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [, $participant] = $this->qualifiedParticipant($auction, 100_000);
        $this->bid($auction, $participant, $participant->user, 100_000, 1);

        app(FinalizeAuctionAction::class)->execute($auction->refresh());

        $settlement = AuctionSettlement::where('auction_id', $auction->id)->where('is_current', true)->firstOrFail();

        return [$auction->refresh(), $settlement];
    }

    private function pendingSellerDepositSubmission(Auction $auction, int $sellerId): void
    {
        \App\Models\Auction\PaymentSubmission::create([
            'auction_id' => $auction->id,
            'user_id' => $sellerId,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => \App\Domain\Auction\Enums\PaymentPurpose::SellerDeposit,
            'status' => \App\Domain\Auction\Enums\PaymentSubmissionStatus::PendingReview,
            'amount_minor' => (int) $auction->seller_deposit_amount_minor,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'deadline-seller-deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'deadline-seller-deposit-'.\Illuminate\Support\Str::ulid(),
            'submitted_at' => Carbon::now(),
        ]);
    }

    private function remindersSent(AuctionSettlement $settlement): array
    {
        return array_values((array) ($settlement->refresh()->payment_reminders_sent ?? []));
    }

    private function reminderMessages(int $auctionId): int
    {
        return OutboxMessage::where('aggregate_id', $auctionId)
            ->where('event_type', 'auction.winner_payment_reminder')
            ->count();
    }
}
