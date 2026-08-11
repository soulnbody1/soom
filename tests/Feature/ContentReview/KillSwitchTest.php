<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use App\Services\ContentReview\Support\ReviewModeResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class KillSwitchTest extends TestCase
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

    public function test_the_switch_beats_a_published_automatic_configuration(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAutomatic);

        config()->set('content_review.enabled', false);

        $this->assertSame(
            ReviewMode::Manual,
            app(ReviewModeResolver::class)->resolve(ReviewableSubjectType::Auction)
        );
    }

    public function test_no_new_review_is_created_while_the_switch_is_off(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAutomatic);

        config()->set('content_review.enabled', false);

        $auction = $this->submitForReview();

        $this->assertSame(0, ContentReview::count());
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_manual_review_keeps_working_while_the_switch_is_off(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAutomatic);
        config()->set('content_review.enabled', false);

        $auction = $this->submitForReview();
        $admin = $this->auctionReviewer();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/auctions/{$auction->public_id}/review", [
                'action' => 'approve',
                'reason' => 'Reviewed by hand while the automated review is switched off.',
            ])
            ->assertOk();

        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);
    }

    public function test_an_in_flight_review_never_applies_an_automatic_decision_after_the_switch_is_thrown(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAutomatic);
        $review = $this->activeReview($auction);

        config()->set('content_review.enabled', false);

        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $processed = $this->processActiveReview($auction);

        $this->assertSame(ContentReviewStatus::Completed, $processed->status);
        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $processed->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
        $this->assertNotNull(ContentReview::whereKey($review->id)->first());
    }

    public function test_the_sweeper_dispatches_nothing_while_the_switch_is_off(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::Shadow);
        $review = $this->activeReview($auction);

        app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Queued->value,
            'queued_at' => now()->subHour(),
        ]);

        config()->set('content_review.enabled', false);
        Queue::fake();

        Artisan::call('content-review:dispatch-pending');

        Queue::assertNothingPushed();
        $this->assertSame(ContentReviewStatus::Queued, $review->refresh()->status);
    }

    public function test_history_is_never_deleted_by_the_switch(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAutomatic);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $this->processActiveReview($auction);

        $reviewsBefore = ContentReview::count();
        $decisionsBefore = ContentReviewDecision::count();

        config()->set('content_review.enabled', false);

        $this->submitForReview($this->draftAuction());

        $this->assertSame($reviewsBefore, ContentReview::count());
        $this->assertSame($decisionsBefore, ContentReviewDecision::count());
    }

    public function test_a_stored_review_stays_readable_while_the_switch_is_off(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::Shadow);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = $this->processActiveReview($auction);

        config()->set('content_review.enabled', false);

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_the_health_endpoint_reports_the_switch_as_off(): void
    {
        config()->set('content_review.enabled', false);

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson('/api/admin/content-review/health')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.mode', 'manual');
    }
}
