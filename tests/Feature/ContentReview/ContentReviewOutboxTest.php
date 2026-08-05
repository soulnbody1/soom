<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\Auction\Enums\OutboxStatus;
use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\OutboxMessage;
use App\Models\ContentReview\ContentReview;
use App\Notifications\AuctionOutboxNotification;
use App\Notifications\ContentReviewAdminNotification;
use App\Services\Auction\Actions\DispatchOutboxMessagesAction;
use App\Services\Auction\Notifications\OutboxNotifier;
use App\Services\ContentReview\Actions\ProcessContentReviewAction;
use App\Services\ContentReview\Notifications\ContentReviewNotificationCatalog;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use App\Services\Outbox\OutboxTopicRouter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class ContentReviewOutboxTest extends TestCase
{
    use BuildsContentReviewFixtures;

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

    public function test_the_dispatcher_now_resolves_the_topic_router(): void
    {
        $this->assertInstanceOf(OutboxTopicRouter::class, app(OutboxNotifier::class));
    }

    public function test_the_router_rejects_an_unknown_topic(): void
    {
        $message = OutboxMessage::create([
            'event_id' => (string) \Illuminate\Support\Str::ulid(),
            'topic' => 'advertising.events',
            'event_type' => 'advertisement.created',
            'aggregate_type' => ContentReview::class,
            'aggregate_id' => 1,
            'payload' => [],
            'status' => OutboxStatus::Pending,
            'available_at' => now(),
        ]);

        $this->expectExceptionMessage('Unsupported outbox topic: advertising.events');

        app(OutboxTopicRouter::class)->notify($message);
    }

    public function test_an_uncatalogued_content_review_event_dead_letters_with_diagnostics(): void
    {
        $message = $this->contentReviewMessage('content_review.invented_event');

        app(DispatchOutboxMessagesAction::class)->execute();

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertStringContainsString('Unsupported content review outbox event', (string) $message->last_error);
    }

    public function test_a_shadow_run_records_queued_and_completed_events_on_the_content_review_topic(): void
    {
        $this->completedShadowReview();

        $events = OutboxMessage::where('topic', ContentReviewNotificationCatalog::TOPIC)
            ->pluck('event_type')
            ->all();

        $this->assertContains('content_review.queued', $events);
        $this->assertContains('content_review.completed', $events);
        $this->assertNotContains('content_review.escalated', $events);

        foreach (OutboxMessage::where('topic', ContentReviewNotificationCatalog::TOPIC)->get() as $message) {
            $this->assertSame(ContentReview::class, $message->aggregate_type);
        }
    }

    public function test_a_provider_failure_records_failed_and_escalated_events(): void
    {
        $this->escalatedReview();

        $events = OutboxMessage::where('topic', ContentReviewNotificationCatalog::TOPIC)
            ->pluck('event_type')
            ->all();

        $this->assertContains('content_review.failed', $events);
        $this->assertContains('content_review.escalated', $events);
    }

    public function test_a_content_review_event_payload_carries_no_seller_or_auction_details(): void
    {
        $review = $this->escalatedReview();
        $auction = \App\Models\Auction\Auction::findOrFail($review->subject_id);

        $message = OutboxMessage::where('topic', ContentReviewNotificationCatalog::TOPIC)
            ->where('event_type', 'content_review.escalated')
            ->firstOrFail();

        $encoded = json_encode($message->payload);

        $this->assertStringNotContainsString($auction->title, (string) $encoded);
        $this->assertStringNotContainsString($auction->description, (string) $encoded);
        $this->assertStringNotContainsString((string) $auction->seller->name, (string) $encoded);
        $this->assertStringNotContainsString((string) $auction->seller->phone, (string) $encoded);
        $this->assertStringNotContainsString((string) $review->content_hash, (string) $encoded);
        $this->assertSame($auction->public_id, $message->payload['subject_reference']);
    }

    public function test_only_admins_holding_the_view_permission_receive_the_alert(): void
    {
        config()->set('content_review.role_admin_permissions', []);

        $permitted = $this->admin(['content_review.view']);
        $otherAdmin = $this->admin(['auction.review']);
        $seller = $this->seller();

        $this->escalatedReview();
        app(DispatchOutboxMessagesAction::class)->execute();

        $this->assertSame(1, $this->alertCount($permitted));
        $this->assertSame(0, $this->alertCount($otherAdmin));
        $this->assertSame(0, $this->alertCount($seller));
    }

    public function test_replaying_the_outbox_never_duplicates_an_admin_alert(): void
    {
        config()->set('content_review.role_admin_permissions', ['content_review.view']);
        $admin = $this->admin();

        $this->escalatedReview();
        app(DispatchOutboxMessagesAction::class)->execute();

        $this->assertSame(1, $this->alertCount($admin));

        OutboxMessage::where('topic', ContentReviewNotificationCatalog::TOPIC)
            ->get()
            ->each(fn (OutboxMessage $message) => $message->forceFill([
                'status' => OutboxStatus::Pending,
                'processed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ])->save());

        app(DispatchOutboxMessagesAction::class)->execute();

        $this->assertSame(1, $this->alertCount($admin));
    }

    public function test_the_per_subject_cooldown_suppresses_a_repeated_alert_for_the_same_subject(): void
    {
        config()->set('content_review.role_admin_permissions', ['content_review.view']);
        $admin = $this->admin();

        $review = $this->escalatedReview();
        app(DispatchOutboxMessagesAction::class)->execute();
        $this->assertSame(1, $this->alertCount($admin));

        $this->contentReviewMessage('content_review.escalated', [
            'review_id' => (string) $review->public_id,
            'subject_type' => 'auction',
            'subject_reference' => (string) \App\Models\Auction\Auction::findOrFail($review->subject_id)->public_id,
        ]);

        app(DispatchOutboxMessagesAction::class)->execute();

        $this->assertSame(1, $this->alertCount($admin));
    }

    public function test_an_operational_alert_is_rate_limited_globally(): void
    {
        config()->set('content_review.role_admin_permissions', ['content_review.view']);
        $admin = $this->admin();

        $this->contentReviewMessage('content_review.budget_exhausted', ['error_code' => 'budget_exhausted']);
        $this->contentReviewMessage('content_review.budget_exhausted', ['error_code' => 'budget_exhausted']);

        app(DispatchOutboxMessagesAction::class)->execute();

        $this->assertSame(1, $this->alertCount($admin));
    }

    public function test_a_budget_exhausted_run_publishes_an_operational_event(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow, ['daily_budget_micros' => 0, 'monthly_budget_micros' => 0]);
        $this->submitForReview();

        $review = ContentReview::firstOrFail();
        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        $this->assertSame(
            ContentReviewErrorCode::BudgetExhausted->value,
            $review->refresh()->error_code?->value
        );
        $this->assertSame(1, OutboxMessage::where('event_type', 'content_review.budget_exhausted')->count());
    }

    public function test_auction_events_still_dispatch_through_the_router_untouched(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Manual);

        $auction = $this->submitForReview();
        $auction->seller->forceFill(['fcm_token' => null])->save();

        $auctionMessages = OutboxMessage::where('topic', OutboxTopicRouter::TOPIC_AUCTION)->count();
        $this->assertGreaterThan(0, $auctionMessages);

        app(DispatchOutboxMessagesAction::class)->execute();

        $this->assertSame(
            $auctionMessages,
            OutboxMessage::where('topic', OutboxTopicRouter::TOPIC_AUCTION)
                ->where('status', OutboxStatus::Processed)
                ->count()
        );

        $this->assertGreaterThan(
            0,
            $auction->seller->notifications()->where('type', AuctionOutboxNotification::class)->count()
        );
    }

    public function test_the_dispatcher_supports_both_catalogues(): void
    {
        $this->assertTrue(DispatchOutboxMessagesAction::supports('auction.status_changed'));
        $this->assertTrue(DispatchOutboxMessagesAction::supports('content_review.escalated'));
        $this->assertFalse(DispatchOutboxMessagesAction::supports('advertisement.created'));
    }

    private function completedShadowReview(): ContentReview
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->submitForReview();

        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = ContentReview::firstOrFail();
        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        return $review->refresh();
    }

    private function escalatedReview(): ContentReview
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAutomatic);
        $this->submitForReview();

        $this->fakeProvider()->failWith(ContentReviewErrorCode::ProviderAuthFailed);
        $review = ContentReview::firstOrFail();
        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        return $review->refresh();
    }

    private function contentReviewMessage(string $eventType, array $payload = []): OutboxMessage
    {
        return OutboxMessage::create([
            'event_id' => (string) \Illuminate\Support\Str::ulid(),
            'topic' => ContentReviewNotificationCatalog::TOPIC,
            'event_type' => $eventType,
            'aggregate_type' => ContentReview::class,
            'aggregate_id' => 0,
            'payload' => $payload,
            'status' => OutboxStatus::Pending,
            'available_at' => now(),
        ]);
    }

    private function alertCount(\App\Models\User $user): int
    {
        return $user->notifications()->where('type', ContentReviewAdminNotification::class)->count();
    }

    private function fakeProvider(): FakeContentReviewProvider
    {
        return app(FakeContentReviewProvider::class);
    }
}
