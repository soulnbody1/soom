<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\OutboxMessage;
use App\Models\ContentReview\ContentReview;
use App\Notifications\ContentReviewAdminNotification;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\Auction\Actions\DispatchOutboxMessagesAction;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewAlertMonitor;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class AlertRateLimitTest extends TestCase
{
    use BuildsContentReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config()->set('content_review.enabled', true);
        config()->set('content_review.alerts.queue_delay_seconds', 60);
        config()->set('content_review.alerts.evaluation_cache_seconds', 1);
        Http::preventStrayRequests();
        Queue::fake();
        app(FakeContentReviewProvider::class)->reset();
        app(ContentReviewCircuitBreaker::class)->reset();
        app(ContentReviewAlertMonitor::class)->reset();
    }

    public function test_a_condition_that_turns_on_publishes_exactly_one_alert(): void
    {
        $this->staleQueuedReview();

        $first = $this->monitor()->sweep(ReviewableSubjectType::Auction);
        $second = $this->monitor()->sweep(ReviewableSubjectType::Auction);

        $this->assertContains(ContentReviewAlertMonitor::QUEUE_DELAY_HIGH, $first['alerted']);
        $this->assertNotContains(ContentReviewAlertMonitor::QUEUE_DELAY_HIGH, $second['alerted']);
        $this->assertSame(1, $this->outboxCount('content_review.queue_delay_high'));
    }

    public function test_a_still_active_condition_re_alerts_only_after_the_repeat_window(): void
    {
        config()->set('content_review.alerts.repeat_seconds', 60);
        $this->staleQueuedReview();

        $this->monitor()->sweep(ReviewableSubjectType::Auction);
        $this->travel(30)->seconds();
        $this->monitor()->sweep(ReviewableSubjectType::Auction);

        $this->assertSame(1, $this->outboxCount('content_review.queue_delay_high'));

        $this->travel(61)->seconds();
        $this->monitor()->sweep(ReviewableSubjectType::Auction);

        $this->assertSame(2, $this->outboxCount('content_review.queue_delay_high'));
    }

    public function test_clearing_a_condition_publishes_one_recovery_event(): void
    {
        $review = $this->staleQueuedReview();

        $this->monitor()->sweep(ReviewableSubjectType::Auction);

        app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Completed->value,
            'current_marker' => null,
        ]);

        $first = $this->monitor()->sweep(ReviewableSubjectType::Auction);
        $second = $this->monitor()->sweep(ReviewableSubjectType::Auction);

        $this->assertContains(ContentReviewAlertMonitor::QUEUE_DELAY_HIGH, $first['recovered']);
        $this->assertSame([], $second['recovered']);
        $this->assertSame(1, $this->outboxCount('content_review.recovered'));
    }

    public function test_a_healthy_platform_publishes_nothing(): void
    {
        $result = $this->monitor()->sweep(ReviewableSubjectType::Auction);

        $this->assertSame([], $result['alerted']);
        $this->assertSame([], $result['recovered']);
        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_an_open_circuit_and_a_recovered_circuit_are_both_reported(): void
    {
        $settings = ['circuit_breaker' => ['failure_threshold' => 1, 'window_seconds' => 300, 'open_seconds' => 600]];
        app(ContentReviewCircuitBreaker::class)->recordFailure($settings);

        $opened = $this->monitor()->sweep(ReviewableSubjectType::Auction);
        $this->assertContains(ContentReviewAlertMonitor::CIRCUIT_OPEN, $opened['alerted']);

        app(ContentReviewCircuitBreaker::class)->recordSuccess();

        $closed = $this->monitor()->sweep(ReviewableSubjectType::Auction);
        $this->assertContains(ContentReviewAlertMonitor::CIRCUIT_OPEN, $closed['recovered']);
    }

    public function test_the_read_only_state_view_publishes_nothing(): void
    {
        $this->staleQueuedReview();

        $states = $this->monitor()->states(ReviewableSubjectType::Auction);
        $queueDelay = $this->stateFor($states, ContentReviewAlertMonitor::QUEUE_DELAY_HIGH);

        $this->assertTrue($queueDelay['active']);
        $this->assertSame(0, $this->outboxCount('content_review.queue_delay_high'));
    }

    public function test_repeated_alerts_notify_an_admin_only_once_per_cooldown(): void
    {
        Notification::fake();
        config()->set('content_review.alerts.repeat_seconds', 1);
        config()->set('content_review.alerts.global_cooldown_seconds', 3600);
        $this->admin(['content_review.view']);
        $this->staleQueuedReview();

        $this->monitor()->sweep(ReviewableSubjectType::Auction);
        $this->travel(61)->seconds();
        $this->monitor()->sweep(ReviewableSubjectType::Auction);

        $this->assertSame(2, $this->outboxCount('content_review.queue_delay_high'));

        app(DispatchOutboxMessagesAction::class)->execute();

        Notification::assertSentTimes(ContentReviewAdminNotification::class, 1);
    }

    public function test_two_different_alerts_recovering_together_both_notify(): void
    {
        Notification::fake();
        $this->admin(['content_review.view']);
        $this->staleQueuedReview();
        app(ContentReviewCircuitBreaker::class)->recordFailure(
            ['circuit_breaker' => ['failure_threshold' => 1, 'window_seconds' => 300, 'open_seconds' => 600]]
        );

        $this->monitor()->sweep(ReviewableSubjectType::Auction);
        app(DispatchOutboxMessagesAction::class)->execute();

        Notification::assertSentTimes(ContentReviewAdminNotification::class, 2);

        ContentReview::query()->update(['status' => ContentReviewStatus::Completed->value, 'current_marker' => null]);
        app(ContentReviewCircuitBreaker::class)->recordSuccess();

        $recovered = $this->monitor()->sweep(ReviewableSubjectType::Auction);

        $this->assertCount(2, $recovered['recovered']);
        $this->assertSame(2, $this->outboxCount('content_review.recovered'));

        app(DispatchOutboxMessagesAction::class)->execute();

        Notification::assertSentTimes(ContentReviewAdminNotification::class, 4);
    }

    private function monitor(): ContentReviewAlertMonitor
    {
        return app(ContentReviewAlertMonitor::class);
    }

    private function staleQueuedReview(): ContentReview
    {
        $auction = $this->reviewedAuction(ReviewMode::Shadow);
        $review = $this->activeReview($auction);

        return app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Queued->value,
            'queued_at' => now()->subHours(3),
        ]);
    }

    private function outboxCount(string $eventType): int
    {
        return OutboxMessage::where('event_type', $eventType)->count();
    }

    private function stateFor(array $states, string $code): array
    {
        foreach ($states as $state) {
            if ($state['code'] === $code) {
                return $state;
            }
        }

        return ['active' => false];
    }
}
