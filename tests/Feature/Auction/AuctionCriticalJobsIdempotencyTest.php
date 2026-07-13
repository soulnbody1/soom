<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\OutboxStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\DTO\Auction\RefundProcessingResult;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\AuctionWinnerReassignment;
use App\Models\Auction\OutboxMessage;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Notifications\AuctionOutboxNotification;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Actions\DispatchOutboxMessagesAction;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use App\Services\Auction\Actions\MarkWinnerDefaultedAction;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use App\Services\Auction\Actions\StartDueAuctionsAction;
use App\Services\Auction\Refunds\AuctionRefundProcessorInterface;
use App\Jobs\Auction\DispatchAuctionOutboxJob;
use App\Jobs\Auction\FinalizeExpiredAuctionsJob;
use App\Jobs\Auction\ProcessPendingAuctionRefundsJob;
use App\Jobs\Auction\StartDueAuctionsJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuctionCriticalJobsIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_start_job_can_run_twice_without_starting_auction_twice(): void
    {
        [$auction] = $this->auction(AuctionStatus::Scheduled);

        $job = app(StartDueAuctionsJob::class);
        $job->handle(app(StartDueAuctionsAction::class));
        $job->handle(app(StartDueAuctionsAction::class));

        $this->assertSame(AuctionStatus::Live, $auction->refresh()->status);
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.status_changed')
            ->where('payload->to', AuctionStatus::Live->value)
            ->count());
    }

    public function test_finalize_job_can_run_twice_without_extra_settlement(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [$winner, $participant] = $this->qualifiedParticipant($auction, 10_000);
        $bid = $this->bid($auction, $participant, $winner, 100_000, 1);
        $this->acceptTerms($auction, $participant, $winner);

        $job = app(FinalizeExpiredAuctionsJob::class);
        $job->handle(app(FinalizeAuctionAction::class), app(AuctionRepository::class));
        $job->handle(app(FinalizeAuctionAction::class), app(AuctionRepository::class));

        $auction->refresh();
        $this->assertSame($bid->id, $auction->winning_bid_id);
        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->count());
        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->where('current_marker', 1)->count());
    }

    public function test_winner_default_retry_does_not_create_extra_alternative_settlement(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [$winner, $winnerParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $winnerParticipant, $winner, 100_000, 1);
        $this->acceptTerms($auction, $winnerParticipant, $winner);
        [$alternative, $alternativeParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $alternativeParticipant, $alternative, 95_000, 2);
        $this->acceptTerms($auction, $alternativeParticipant, $alternative);

        $finalized = app(FinalizeAuctionAction::class)->execute($auction);
        $finalized->settlement->forceFill(['payment_due_at' => Carbon::now()->subMinute()])->save();
        $admin = $this->user('admin');

        $action = app(MarkWinnerDefaultedAction::class);
        $action->execute($finalized->refresh(), $admin->id, 'deadline expired', true);
        try {
            $action->execute($finalized->refresh(), $admin->id, 'deadline expired', true);
        } catch (\Throwable) {
            // A retry after the first success may see the new winner before their deadline.
        }

        $this->assertSame(2, AuctionSettlement::where('auction_id', $auction->id)->count());
        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->where('current_marker', 1)->count());
        $this->assertSame(1, AuctionWinnerReassignment::where('auction_id', $auction->id)->count());
    }

    public function test_refund_job_can_run_twice_without_processing_refund_twice(): void
    {
        [$auction] = $this->auction(AuctionStatus::Completed);
        [$user, $participant] = $this->qualifiedParticipant($auction, 10_000);
        $deposit = AuctionDeposit::where('participant_id', $participant->id)->firstOrFail();
        $payment = PaymentTransaction::where('successful_obligation_key', "deposit:{$deposit->id}")->firstOrFail();
        $refund = RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'payment_transaction_id' => $payment->id,
            'obligation_type' => 'deposit',
            'obligation_id' => $deposit->id,
            'user_id' => $user->id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => 10_000,
            'held_refund_amount_minor' => 10_000,
            'applied_refund_amount_minor' => 0,
            'currency_code' => 'JOD',
            'provider' => 'manual',
            'idempotency_key' => 'critical-refund-'.Str::ulid(),
            'reason' => 'job idempotency',
        ]);
        $this->app->instance(AuctionRefundProcessorInterface::class, new class implements AuctionRefundProcessorInterface {
            public function process(RefundTransaction $refund): RefundProcessingResult
            {
                return RefundProcessingResult::succeeded('critical-refund-'.$refund->id);
            }
        });

        $job = app(ProcessPendingAuctionRefundsJob::class);
        $job->handle(app(AuctionRefundRepository::class), app(ProcessAuctionRefundAction::class));
        $job->handle(app(AuctionRefundRepository::class), app(ProcessAuctionRefundAction::class));

        $this->assertSame(RefundTransactionStatus::Succeeded, $refund->refresh()->status);
        $this->assertSame(1, $refund->attempt_count);
        $this->assertSame(10_000, $deposit->refresh()->refunded_amount_minor);
    }

    public function test_outbox_job_can_run_twice_without_duplicate_notification(): void
    {
        Notification::fake();
        [$auction, $seller] = $this->auction(AuctionStatus::Live);
        OutboxMessage::create([
            'event_id' => (string) Str::ulid(),
            'topic' => 'auction.events',
            'event_type' => 'auction.status_changed',
            'aggregate_type' => Auction::class,
            'aggregate_id' => $auction->id,
            'payload' => [
                'auction_id' => $auction->id,
                'from' => AuctionStatus::Scheduled->value,
                'to' => AuctionStatus::Live->value,
            ],
            'status' => OutboxStatus::Pending,
            'available_at' => Carbon::now()->subMinute(),
        ]);

        $job = app(DispatchAuctionOutboxJob::class);
        $job->handle(app(DispatchOutboxMessagesAction::class));
        $job->handle(app(DispatchOutboxMessagesAction::class));

        Notification::assertSentToTimes($seller, AuctionOutboxNotification::class, 1);
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('status', OutboxStatus::Processed)
            ->count());
    }

    private function auction(AuctionStatus $status): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $configuration = AuctionConfigurationVersion::create([
            'version_number' => ((int) AuctionConfigurationVersion::max('version_number')) + 1,
            'configuration' => [
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
                'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
            ],
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $category = Category::create(['name' => 'critical-job-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'critical-job-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Critical job auction',
            'description' => 'Critical job auction.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => Carbon::now()->subDays(2),
            'original_ends_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->subMinute(),
        ]);
        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction, $seller->id);

        return [$auction->refresh(), $seller];
    }

    private function qualifiedParticipant(Auction $auction, int $heldDeposit): array
    {
        $user = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => Carbon::now()->subDays(2),
            'qualified_at' => Carbon::now()->subDay(),
        ]);
        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $heldDeposit,
            'held_amount_minor' => $heldDeposit,
            'currency_code' => $auction->currency_code,
            'held_at' => Carbon::now()->subDay(),
        ]);
        $this->successfulDepositPayment($auction, $deposit, $user->id, $heldDeposit);

        return [$user, $participant];
    }

    private function bid(Auction $auction, AuctionParticipant $participant, User $user, int $amount, int $sequence): AuctionBid
    {
        return AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'sequence_number' => $sequence,
            'idempotency_key' => 'critical-job-bid-'.$sequence.'-'.Str::ulid(),
            'server_received_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
        ]);
    }

    private function acceptTerms(Auction $auction, AuctionParticipant $participant, User $user): void
    {
        AuctionTermsAcceptance::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'terms_version_id' => $auction->terms_version_id,
            'accepted_at' => Carbon::now()->subDay(),
        ]);
    }

    private function successfulDepositPayment(Auction $auction, AuctionDeposit $deposit, int $userId, int $amount): void
    {
        $method = PaymentMethod::create([
            'name' => 'Critical job payment method',
            'code' => 'critical-job-'.Str::ulid(),
            'instructions' => 'Test method.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $userId,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'critical-job-deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'critical-job-deposit-'.Str::ulid(),
            'submitted_at' => Carbon::now(),
            'reviewed_at' => Carbon::now(),
        ]);
        PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $userId,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'provider' => 'manual',
            'provider_transaction_id' => 'critical-job-deposit-'.Str::ulid(),
            'idempotency_key' => 'critical-job-deposit-'.Str::ulid(),
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => Carbon::now(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "critical-job-{$unique}@example.test",
            'phone' => '+96277'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
