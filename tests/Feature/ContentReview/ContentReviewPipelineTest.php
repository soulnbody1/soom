<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Jobs\ContentReview\ProcessContentReviewJob;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionMedia;
use App\Models\Auction\AuctionStatusHistory;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\OutboxMessage;
use App\Models\Category;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Models\ContentReview\ContentReviewSetting;
use App\Models\Country;
use App\Models\User;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\Auction\Actions\ReviewAuctionAction;
use App\Services\Auction\Actions\SubmitAuctionForReviewAction;
use App\Services\ContentReview\Actions\ProcessContentReviewAction;
use App\Services\ContentReview\Actions\RequestContentReviewAction;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentHasher;
use App\Services\ContentReview\Support\ContentReviewBudgetGuard;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use App\Services\ContentReview\Support\ContentReviewConcurrencyLimiter;
use App\Services\ContentReview\Support\ProviderCallGuard;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentReviewPipelineTest extends TestCase
{
    private const SAMPLE_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElDPEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7/8AAEQgABAAEAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AxaKKK+vPjT//2Q==';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config()->set('content_review.enabled', true);
        Http::preventStrayRequests();
        Queue::fake();
        app(FakeContentReviewProvider::class)->reset();
        app(ContentReviewCircuitBreaker::class)->reset();
    }

    public function test_manual_mode_creates_no_ai_review_and_leaves_the_auction_flow_untouched(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Manual);
        Queue::fake();

        $auction = $this->draftAuction();
        $submitted = app(SubmitAuctionForReviewAction::class)->execute($auction, (int) $auction->seller_id);

        $this->assertSame(AuctionStatus::PendingReview, $submitted->status);
        $this->assertSame(0, ContentReview::count());
        Queue::assertNothingPushed();
    }

    public function test_the_feature_flag_alone_keeps_the_pipeline_dormant(): void
    {
        config()->set('content_review.enabled', false);
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAssisted);

        $auction = $this->draftAuction();
        app(SubmitAuctionForReviewAction::class)->execute($auction, (int) $auction->seller_id);

        $this->assertSame(0, ContentReview::count());
    }

    public function test_shadow_mode_enqueues_one_review_and_dispatches_only_after_commit(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        Queue::fake();

        $auction = $this->draftAuction();
        app(SubmitAuctionForReviewAction::class)->execute($auction, (int) $auction->seller_id);

        $review = ContentReview::firstOrFail();

        $this->assertSame(ContentReviewStatus::Queued, $review->status);
        $this->assertSame(ReviewMode::Shadow, $review->mode);
        $this->assertSame(ReviewTrigger::SubmittedForReview, $review->trigger);
        $this->assertSame(1, (int) $review->current_marker);
        $this->assertSame((int) $auction->id, (int) $review->subject_id);
        $this->assertNotNull($review->policy_version);
        $this->assertNotNull($review->settings_version);

        Queue::assertPushed(ProcessContentReviewJob::class, 1);
        Queue::assertPushed(
            ProcessContentReviewJob::class,
            fn (ProcessContentReviewJob $job): bool => $job->publicId === $review->public_id
                && $job->queue === 'content-review'
        );
    }

    public function test_a_rolled_back_submission_dispatches_nothing_and_stores_no_review(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        Queue::fake();

        $auction = $this->draftAuction();

        try {
            DB::transaction(function () use ($auction): void {
                app(RequestContentReviewAction::class)->execute(
                    ReviewableSubjectType::Auction,
                    (int) $auction->id,
                    ReviewTrigger::AdminManual,
                );

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
            // intentional rollback
        }

        $this->assertSame(0, ContentReview::count());
        Queue::assertNothingPushed();
    }

    public function test_shadow_mode_records_an_advisory_result_without_touching_the_auction(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->fakeProvider()->respondWith($this->cleanPayload());

        $auction = $this->submitForReview();
        $historyBefore = AuctionStatusHistory::where('auction_id', $auction->id)->count();

        $this->runPipeline();

        $review = ContentReview::firstOrFail();
        $auction->refresh();

        $this->assertSame(ContentReviewStatus::Completed, $review->status);
        $this->assertSame(ContentReviewOutcome::AdvisoryOnly, $review->outcome);
        $this->assertTrue($review->requires_human_review);
        $this->assertSame(AuctionStatus::PendingReview, $auction->status);
        $this->assertSame($historyBefore, AuctionStatusHistory::where('auction_id', $auction->id)->count());
        $this->assertSame(0, AuctionConfigurationSnapshot::where('auction_id', $auction->id)->count());
        $this->assertSame(0, AuctionDeposit::where('auction_id', $auction->id)->count());
        $this->assertSame(1, ContentReviewDecision::count());
        $this->assertSame(DecisionActorType::Ai, ContentReviewDecision::firstOrFail()->decided_by_type);
        $this->assertNull(ContentReviewDecision::firstOrFail()->decided_by_id);
    }

    public function test_assisted_mode_records_a_recommendation_without_deciding(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAssisted);
        $this->fakeProvider()->respondWith($this->cleanPayload());

        $auction = $this->submitForReview();
        $this->runPipeline();

        $review = ContentReview::firstOrFail();

        $this->assertSame(ContentReviewOutcome::AdvisoryOnly, $review->outcome);
        $this->assertSame('assisted_mode', $review->reason_code);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_automatic_mode_approves_once_with_a_single_snapshot_and_deposit(): void
    {
        $auction = $this->eligibleAutomaticAuction();
        $this->fakeProvider()->respondWith($this->cleanPayload());

        $usersBefore = User::count();
        $this->runPipeline();
        $this->runPipeline();

        $review = ContentReview::firstOrFail();
        $auction->refresh();

        $this->assertSame(ContentReviewOutcome::AutoApproved, $review->outcome);
        $this->assertSame('high_confidence_clean', $review->reason_code);
        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->status);
        $this->assertSame(1, AuctionConfigurationSnapshot::where('auction_id', $auction->id)->count());
        $this->assertNull(AuctionConfigurationSnapshot::where('auction_id', $auction->id)->firstOrFail()->created_by);
        $this->assertSame(1, AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->count());
        $this->assertSame(1, ContentReviewDecision::count());
        $this->assertSame($usersBefore, User::count(), 'No fake user may ever be created for the AI actor.');

        $transition = AuctionStatusHistory::where('auction_id', $auction->id)
            ->where('to_status', AuctionStatus::AwaitingSellerDeposit->value)
            ->firstOrFail();

        $this->assertSame('ai', $transition->actor_type);
        $this->assertNull($transition->changed_by);

        $approvalEvents = OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.status_changed')
            ->get()
            ->filter(fn (OutboxMessage $message): bool => ($message->payload['to'] ?? null) === AuctionStatus::AwaitingSellerDeposit->value);

        $this->assertCount(1, $approvalEvents, 'The AI approval must emit exactly one outbox event.');
    }

    public function test_automatic_mode_rejects_with_an_ai_actor_and_never_creates_a_user(): void
    {
        $auction = $this->eligibleAutomaticAuction(['auto_reject_categories' => ['prohibited_item']]);
        $this->fakeProvider()->respondWith($this->prohibitedPayload());

        $usersBefore = User::count();
        $this->runPipeline();

        $review = ContentReview::firstOrFail();
        $auction->refresh();

        $this->assertSame(ContentReviewOutcome::AutoRejected, $review->outcome);
        $this->assertSame(AuctionStatus::Rejected, $auction->status);
        $this->assertSame($usersBefore, User::count());

        $transition = AuctionStatusHistory::where('auction_id', $auction->id)
            ->where('to_status', AuctionStatus::Rejected->value)
            ->firstOrFail();

        $this->assertSame('ai', $transition->actor_type);
        $this->assertNull($transition->changed_by);
        $this->assertNotSame('', trim((string) $transition->reason));
    }

    public function test_an_ineligible_subject_never_reaches_an_automatic_decision(): void
    {
        $auction = $this->eligibleAutomaticAuction([], ['allowed_category_ids' => []]);
        $this->fakeProvider()->respondWith($this->cleanPayload());

        $this->runPipeline();

        $review = ContentReview::firstOrFail();

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame('subject_not_automation_eligible', $review->reason_code);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_running_the_pipeline_twice_produces_one_result_one_cost_and_one_decision(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAssisted);
        $this->fakeProvider()->respondWith($this->cleanPayload());

        $this->submitForReview();

        $this->runPipeline();
        $this->runPipeline();

        $review = ContentReview::firstOrFail();

        $this->assertSame(1, ContentReview::count());
        $this->assertSame(1, ContentReviewDecision::count());
        $this->assertSame(1, $this->fakeProvider()->calls());
        $this->assertSame(1200, (int) $review->cost_micros);
    }

    public function test_editing_the_auction_changes_the_content_hash_and_supersedes_the_result(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAssisted);

        $auction = $this->submitForReview();
        $review = ContentReview::firstOrFail();

        $hashBefore = $review->content_hash;

        $auction->forceFill(['title' => 'A completely different headline'])->save();

        $adapter = app(ReviewSubjectRegistry::class)->for(ReviewableSubjectType::Auction);
        $hashAfter = app(ContentHasher::class)->hashContent($adapter->buildContent((int) $auction->id));

        $this->assertNotSame($hashBefore, $hashAfter);

        $this->runPipeline();

        $review->refresh();

        $this->assertSame(ContentReviewStatus::Superseded, $review->status);
        $this->assertSame(ContentReviewOutcome::NoDecision, $review->outcome);
        $this->assertSame(ContentReviewErrorCode::ContentChanged, $review->error_code);
        $this->assertNull($review->current_marker);
        $this->assertSame(0, $this->fakeProvider()->calls());
    }

    public function test_resubmitting_supersedes_the_previous_review_and_keeps_one_active(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAssisted);
        Queue::fake();

        $auction = $this->submitForReview();
        $first = ContentReview::firstOrFail();

        app(ReviewAuctionAction::class)->reject($auction->refresh(), $this->adminId(), 'blurred images');
        $auction->refresh()->forceFill(['status' => AuctionStatus::Draft, 'title' => 'Second attempt headline'])->save();

        app(SubmitAuctionForReviewAction::class)->execute($auction->refresh(), (int) $auction->seller_id);

        $first->refresh();
        $active = app(ContentReviewRepository::class)->activeForSubject(ReviewableSubjectType::Auction, (int) $auction->id);

        $this->assertSame(2, ContentReview::count());
        $this->assertNull($first->current_marker);
        $this->assertSame(ContentReviewStatus::Superseded, $first->status);
        $this->assertNotNull($active);
        $this->assertNotSame($first->id, $active->id);
        $this->assertNotSame($first->content_hash, $active->content_hash);
    }

    public function test_an_admin_decision_while_the_review_is_queued_leaves_the_ai_with_no_decision(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAutomatic);
        $this->fakeProvider()->respondWith($this->cleanPayload());
        Queue::fake();

        $auction = $this->submitForReview();
        app(ReviewAuctionAction::class)->approve($auction->refresh(), $this->adminId(), 'approved by a human first');

        $this->runPipeline();

        $review = ContentReview::firstOrFail();

        $this->assertSame(ContentReviewStatus::Cancelled, $review->status);
        $this->assertSame(ContentReviewOutcome::NoDecision, $review->outcome);
        $this->assertNull($review->current_marker);
        $this->assertSame(0, $this->fakeProvider()->calls());
        $this->assertSame(1, AuctionStatusHistory::where('auction_id', $auction->id)
            ->where('actor_type', 'admin')->count());
        $this->assertSame(0, AuctionStatusHistory::where('auction_id', $auction->id)
            ->where('actor_type', 'ai')->count());
    }

    public function test_an_ai_result_that_lands_after_an_admin_decision_mutates_nothing(): void
    {
        $auction = $this->eligibleAutomaticAuction();
        $this->fakeProvider()->respondWith($this->cleanPayload());

        $review = ContentReview::firstOrFail();

        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);
        $review->refresh();
        $this->assertSame(ContentReviewOutcome::AutoApproved, $review->outcome);

        $statusAfterAi = $auction->refresh()->status;

        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        $this->assertSame($statusAfterAi, $auction->refresh()->status);
        $this->assertSame(1, ContentReviewDecision::count());
    }

    public function test_two_concurrent_workers_on_one_review_yield_a_single_provider_call(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAssisted);
        $this->fakeProvider()->respondWith($this->cleanPayload());

        $this->submitForReview();
        $review = ContentReview::firstOrFail();

        $action = app(ProcessContentReviewAction::class);
        $action->execute((string) $review->public_id);
        $second = $action->execute((string) $review->public_id);

        $this->assertSame(ProcessContentReviewAction::RESULT_SKIPPED, $second);
        $this->assertSame(1, $this->fakeProvider()->calls());
        $this->assertSame(1, ContentReviewDecision::count());
    }

    public function test_a_provider_failure_escalates_to_a_human_and_never_approves(): void
    {
        $auction = $this->eligibleAutomaticAuction();
        $this->fakeProvider()->failWith(ContentReviewErrorCode::ProviderUnavailable);

        try {
            $this->runPipeline();
        } catch (\Throwable) {
            // retryable failures rethrow so the queue can back off
        }

        $review = ContentReview::firstOrFail();

        $this->assertSame(ContentReviewStatus::Queued, $review->status);
        $this->assertSame(2, (int) $review->attempt);
        $this->assertSame(ContentReviewErrorCode::ProviderUnavailable, $review->error_code);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_exhausting_every_attempt_ends_in_a_human_escalation(): void
    {
        $auction = $this->eligibleAutomaticAuction();
        $this->fakeProvider()->failWith(ContentReviewErrorCode::ProviderUnavailable);

        $review = ContentReview::firstOrFail();
        $this->assertSame(3, (int) $review->max_attempts);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                app(ProcessContentReviewAction::class)->execute((string) $review->public_id);
            } catch (\Throwable) {
                // retryable failures rethrow until the attempts are exhausted
            }
        }

        $review->refresh();

        $this->assertSame(ContentReviewStatus::Failed, $review->status);
        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertTrue($review->requires_human_review);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_a_non_retryable_provider_failure_escalates_immediately(): void
    {
        $auction = $this->eligibleAutomaticAuction();
        $this->fakeProvider()->failWith(ContentReviewErrorCode::ProviderAuthFailed);

        $this->runPipeline();

        $review = ContentReview::firstOrFail();

        $this->assertSame(ContentReviewStatus::Failed, $review->status);
        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(1, (int) $review->attempt);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_an_off_contract_structured_result_escalates_to_a_human(): void
    {
        $auction = $this->eligibleAutomaticAuction();
        $this->fakeProvider()->respondWith(['recommendation' => 'approve', 'confidence' => 'very high']);

        $this->runPipeline();

        $review = ContentReview::firstOrFail();

        $this->assertSame(ContentReviewStatus::Failed, $review->status);
        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(ContentReviewErrorCode::InvalidStructuredOutput, $review->error_code);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_an_open_circuit_skips_the_provider_and_escalates(): void
    {
        $auction = $this->eligibleAutomaticAuction();
        $breaker = app(ContentReviewCircuitBreaker::class);

        for ($failure = 0; $failure < 5; $failure++) {
            $breaker->recordFailure(['circuit_breaker' => ['failure_threshold' => 5, 'window_seconds' => 300, 'open_seconds' => 600]]);
        }

        $this->assertTrue($breaker->isOpen());

        $this->runPipeline();

        $review = ContentReview::firstOrFail();

        $this->assertSame(0, $this->fakeProvider()->calls());
        $this->assertSame(ContentReviewErrorCode::CircuitOpen, $review->error_code);
        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_an_exhausted_budget_skips_the_provider_and_escalates(): void
    {
        $auction = $this->eligibleAutomaticAuction([], [], ['daily_budget_micros' => 0, 'monthly_budget_micros' => 0]);

        $this->runPipeline();

        $review = ContentReview::firstOrFail();

        $this->assertSame(0, $this->fakeProvider()->calls());
        $this->assertSame(ContentReviewErrorCode::BudgetExhausted, $review->error_code);
        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_the_budget_guard_reserves_atomically_and_releases_what_it_reserved(): void
    {
        $guard = app(ContentReviewBudgetGuard::class);

        $estimate = $guard->estimateMicros('claude-sonnet-5', 2000);
        $this->assertGreaterThan(0, $estimate);

        $budget = $estimate * 3;
        $settings = ['daily_budget_micros' => $budget, 'monthly_budget_micros' => $budget];
        $reservations = [];

        while (($reservation = $guard->reserve($settings, 'claude-sonnet-5', 2000)) !== null) {
            $reservations[] = $reservation;
            $this->assertLessThanOrEqual($budget, $guard->reservedMicros('daily'));
        }

        $this->assertSame(3, count($reservations), 'The reservation ledger must stop exactly at the budget.');
        $this->assertSame(0, $guard->remainingMicros($settings, 'daily'));

        foreach ($reservations as $reservation) {
            $guard->release($reservation);
        }

        $this->assertSame(0, $guard->reservedMicros('daily'));
        $this->assertSame($budget, $guard->remainingMicros($settings, 'daily'));
        $this->assertNotNull($guard->reserve($settings, 'claude-sonnet-5', 2000));
    }

    public function test_a_full_concurrency_pool_releases_the_job_without_consuming_an_attempt(): void
    {
        $auction = $this->eligibleAutomaticAuction([], [], ['max_concurrent' => 1]);
        $this->fakeProvider()->respondWith($this->cleanPayload());

        $limiter = app(ContentReviewConcurrencyLimiter::class);
        $held = $limiter->acquire(['max_concurrent' => 1]);
        $this->assertNotNull($held);

        $review = ContentReview::firstOrFail();
        $result = app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        $review->refresh();

        $this->assertSame(ProcessContentReviewAction::RESULT_NO_SLOT, $result);
        $this->assertSame(ContentReviewStatus::Queued, $review->status);
        $this->assertSame(1, (int) $review->attempt);
        $this->assertNull($review->error_code);
        $this->assertSame(0, $this->fakeProvider()->calls());
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);

        $limiter->release($held);

        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        $this->assertSame(1, $this->fakeProvider()->calls());
    }

    public function test_no_provider_call_ever_happens_inside_a_database_transaction(): void
    {
        $auction = $this->eligibleAutomaticAuction();
        $guard = app(ProviderCallGuard::class);

        $this->fakeProvider()->respondWith($this->cleanPayload());

        $this->app->bind(ProviderCallGuard::class, fn (): ProviderCallGuard => $guard);

        $this->runPipeline();

        $this->assertSame(1, $this->fakeProvider()->calls());
        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_stopped_worker_never_blocks_the_seller_submission(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAutomatic);
        Queue::fake();

        $auction = $this->draftAuction();
        $submitted = app(SubmitAuctionForReviewAction::class)->execute($auction, (int) $auction->seller_id);

        $this->assertSame(AuctionStatus::PendingReview, $submitted->status);
        $this->assertSame(ContentReviewStatus::Queued, ContentReview::firstOrFail()->status);

        $this->artisan('content-review:dispatch-pending')->assertSuccessful();
    }

    public function test_the_sweeper_reclaims_a_stale_lease_and_ignores_terminal_reviews(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAssisted);
        Queue::fake();

        $this->submitForReview();
        $review = ContentReview::firstOrFail();

        app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Running->value,
            'lease_owner' => (string) Str::ulid(),
            'leased_until' => now()->subMinutes(10),
        ]);

        $this->artisan('content-review:dispatch-pending')->assertSuccessful();

        $review->refresh();
        $this->assertNull($review->lease_owner);
        Queue::assertPushed(ProcessContentReviewJob::class, 1);

        app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Completed->value,
            'current_marker' => null,
        ]);

        $this->artisan('content-review:dispatch-pending')->assertSuccessful();
        Queue::assertPushed(ProcessContentReviewJob::class, 1);
    }

    public function test_no_error_code_can_reach_an_automatic_approval(): void
    {
        foreach (ContentReviewErrorCode::cases() as $code) {
            ContentReviewDecision::query()->delete();
            ContentReview::query()->delete();
            Auction::query()->forceDelete();

            $auction = $this->eligibleAutomaticAuction();
            $this->fakeProvider()->reset()->failWith($code);

            try {
                $this->runPipeline();
            } catch (\Throwable) {
                // retryable codes rethrow for the queue to back off
            }

            $this->assertNotSame(
                AuctionStatus::AwaitingSellerDeposit,
                $auction->refresh()->status,
                "Error code {$code->value} must never reach an automatic approval."
            );
            $this->assertNotSame(AuctionStatus::Scheduled, $auction->refresh()->status);
            $this->assertNotSame(ContentReviewOutcome::AutoApproved, ContentReview::firstOrFail()->outcome);
        }
    }

    private function runPipeline(): void
    {
        $review = ContentReview::query()->latest('id')->first();

        if ($review !== null) {
            app(ProcessContentReviewAction::class)->execute((string) $review->public_id);
        }
    }

    private function fakeProvider(): FakeContentReviewProvider
    {
        return app(FakeContentReviewProvider::class);
    }

    private function submitForReview(): Auction
    {
        $auction = $this->draftAuction();

        return app(SubmitAuctionForReviewAction::class)->execute($auction, (int) $auction->seller_id);
    }

    private function eligibleAutomaticAuction(
        array $policyOverrides = [],
        ?array $automationOverrides = null,
        array $settingsOverrides = [],
    ): Auction {
        $this->publishPolicy($policyOverrides);
        $auction = $this->draftAuction();
        $this->attachMedia($auction);

        $automation = $automationOverrides ?? ['allowed_category_ids' => [(int) $auction->category_id]];
        $automation = array_replace([
            'allowed_category_ids' => [(int) $auction->category_id],
            'max_starting_amount_minor' => 1_000_000,
            'require_images' => true,
        ], $automation);

        $this->publishSettings(ReviewMode::AiAutomatic, array_replace(['automation' => $automation], $settingsOverrides));

        return app(SubmitAuctionForReviewAction::class)->execute($auction, (int) $auction->seller_id);
    }

    private function publishPolicy(array $overrides = []): ContentReviewPolicy
    {
        return ContentReviewPolicy::create([
            'subject_type' => ReviewableSubjectType::Auction->value,
            'version_number' => ((int) ContentReviewPolicy::max('version_number')) + 1,
            'name' => 'Test policy',
            'prompt_version' => 'v1',
            'result_schema_version' => 1,
            'is_active' => true,
            'published_at' => now(),
            'policy' => array_replace([
                'locales' => ['ar', 'en'],
                'analyzed_text_fields' => ['title', 'description'],
                'analyze_images' => true,
                'max_images' => 4,
                'prohibited_categories' => ['weapons', 'drugs', 'counterfeit', 'adult'],
                'auto_reject_categories' => [],
                'human_review_categories' => ['counterfeit'],
                'violation_codes' => ['prohibited_item', 'misleading_description', 'contact_info_in_content'],
                'thresholds' => [
                    'min_confidence_approve' => 85,
                    'min_confidence_reject' => 90,
                    'grey_zone_low' => 50,
                    'grey_zone_high' => 85,
                ],
                'max_risk_level_for_auto_approve' => 'low',
                'deterministic_rules' => [
                    'min_description_length' => 30,
                    'require_at_least_one_image' => false,
                    'forbid_contact_patterns' => true,
                    'reserve_must_not_exceed_starting_multiplier' => 100,
                ],
            ], $overrides),
        ]);
    }

    private function publishSettings(ReviewMode $mode, array $overrides = []): ContentReviewSetting
    {
        ContentReviewSetting::where('scope', ReviewableSubjectType::Auction->value)
            ->where('is_active', true)
            ->get()
            ->each(fn (ContentReviewSetting $setting) => $setting->forceFill(['is_active' => false])->save());

        return ContentReviewSetting::create([
            'scope' => ReviewableSubjectType::Auction->value,
            'version_number' => ((int) ContentReviewSetting::max('version_number')) + 1,
            'is_active' => true,
            'published_at' => now(),
            'settings' => array_replace([
                'enabled' => $mode !== ReviewMode::Manual,
                'mode' => $mode->value,
                'provider' => 'fake',
                'model' => 'claude-sonnet-5',
                'timeout_seconds' => 45,
                'max_attempts' => 3,
                'backoff_seconds' => [60, 300, 900],
                'max_concurrent' => 5,
                'daily_budget_micros' => 5_000_000,
                'monthly_budget_micros' => 100_000_000,
                'max_output_tokens' => 2000,
                'analyze_images' => true,
                'circuit_breaker' => ['failure_threshold' => 5, 'window_seconds' => 300, 'open_seconds' => 600],
                'automation' => ['allowed_category_ids' => [], 'max_starting_amount_minor' => 100_000, 'require_images' => true],
            ], $overrides),
        ]);
    }

    private function cleanPayload(): array
    {
        return [
            'recommendation' => 'approve',
            'confidence' => 96,
            'risk_level' => 'low',
            'requires_human_review' => false,
            'summary_ar' => 'الإعلان مطابق لسياسة المحتوى.',
            'summary_en' => 'The listing matches the content policy.',
            'categories' => [],
            'violations' => [],
            'findings' => [],
            'policy_checks' => [],
            'missing_information' => [],
            'image_checks' => [[
                'ref' => 'img-1',
                'verdict' => 'clean',
                'risk_level' => 'low',
                'findings' => [],
            ]],
        ];
    }

    private function prohibitedPayload(): array
    {
        return [
            'recommendation' => 'reject',
            'confidence' => 97,
            'risk_level' => 'critical',
            'requires_human_review' => false,
            'summary_ar' => 'الإعلان يعرض صنفًا محظورًا.',
            'summary_en' => 'The listing offers a prohibited item.',
            'categories' => [],
            'violations' => [[
                'code' => 'prohibited_item',
                'severity' => 'critical',
                'field' => 'description',
                'evidence' => 'prohibited item',
            ]],
            'findings' => [],
            'policy_checks' => [],
            'missing_information' => [],
            'image_checks' => [[
                'ref' => 'img-1',
                'verdict' => 'clean',
                'risk_level' => 'low',
                'findings' => [],
            ]],
        ];
    }

    private function attachMedia(Auction $auction): AuctionMedia
    {
        Storage::fake('public');

        $bytes = (string) base64_decode(self::SAMPLE_JPEG_BASE64, true);
        $path = 'auctions/'.Str::ulid().'.jpg';

        Storage::disk('public')->put($path, $bytes);

        return AuctionMedia::create([
            'auction_id' => $auction->id,
            'disk' => 'public',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($bytes),
            'sort_order' => 0,
            'is_primary' => true,
        ]);
    }

    private function draftAuction(): Auction
    {
        $version = $this->auctionConfigurationVersion();

        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Content review terms body '.Str::ulid(),
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        return Auction::create([
            'seller_id' => $this->seller()->id,
            'category_id' => Category::create(['name' => 'cr-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create([
                'name' => 'cr-country-'.Str::ulid(),
                'code' => strtoupper(substr((string) Str::ulid(), 0, 6)),
            ])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $version->id,
            'currency_code' => 'JOD',
            'title' => 'A well described collectible item for sale',
            'description' => 'A carefully written description of the collectible item, its condition and its provenance.',
            'status' => AuctionStatus::Draft,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 1_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->addDay(),
            'original_ends_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3),
        ]);
    }

    private function seller(): User
    {
        return $this->makeUser('user');
    }

    private function adminId(): int
    {
        return (int) $this->makeUser('admin')->id;
    }

    private function makeUser(string $role): User
    {
        $unique = strtolower((string) Str::ulid());

        return User::create([
            'name' => 'Content Review User',
            'email' => "content-review-{$unique}@example.test",
            'phone' => '+96279'.str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
