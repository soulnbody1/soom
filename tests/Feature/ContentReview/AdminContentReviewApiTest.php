<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Actions\ProcessContentReviewAction;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class AdminContentReviewApiTest extends TestCase
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

    public function test_history_lists_every_attempt_for_a_subject_newest_first(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);

        $auction = $this->submitForReview();
        $first = ContentReview::firstOrFail();

        app(ContentReviewRepository::class)->update($first, [
            'status' => ContentReviewStatus::Failed->value,
            'current_marker' => null,
        ]);
        $this->submitForReview($this->draftAuction(['seller_id' => $auction->seller_id]));

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/auction/{$auction->public_id}")
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($first->public_id, $response->json('data.0.id'));
    }

    public function test_current_returns_the_active_attempt_and_a_null_payload_when_there_is_none(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Manual);

        $auction = $this->submitForReview();
        $admin = $this->fullyPermittedAdmin();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/content-reviews/auction/{$auction->public_id}/current")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->publishSettings(ReviewMode::Shadow);
        $resubmitted = $this->submitForReview($this->draftAuction());

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/content-reviews/auction/{$resubmitted->public_id}/current")
            ->assertOk()
            ->assertJsonPath('data.status', ContentReviewStatus::Queued->value)
            ->assertJsonPath('data.mode', ReviewMode::Shadow->value);
    }

    public function test_show_exposes_the_redacted_result_with_labels(): void
    {
        $review = $this->completedReview();

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk()
            ->assertJsonPath('data.status', ContentReviewStatus::Completed->value)
            ->assertJsonPath('data.recommendation', 'approve')
            ->assertJsonPath('data.confidence', 96)
            ->assertJsonPath('data.risk_level', 'low')
            ->assertJsonPath('data.is_stale', false)
            ->assertJsonStructure(['data' => [
                'status_label', 'mode_label', 'outcome_label', 'recommendation_label', 'risk_level_label',
                'summary_ar', 'summary_en', 'violations', 'findings', 'missing_information',
                'queued_at', 'started_at', 'completed_at', 'decided_at', 'available_actions',
                'technical' => ['provider', 'model', 'policy_version', 'settings_version', 'duration_ms'],
                'cost' => ['input_tokens', 'output_tokens', 'cost_micros'],
            ]]);
    }

    public function test_an_unknown_review_id_returns_a_stable_not_found_code(): void
    {
        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson('/api/admin/content-reviews/01JUNKJUNKJUNKJUNKJUNKJUNK')
            ->assertNotFound()
            ->assertJsonPath('code', 'review_not_found');
    }

    public function test_an_unsupported_subject_type_is_rejected_by_the_route(): void
    {
        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson('/api/admin/content-reviews/advertisement/1')
            ->assertNotFound();
    }

    public function test_run_supersedes_the_active_attempt_and_creates_a_new_one(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);

        $auction = $this->submitForReview();
        $first = ContentReview::firstOrFail();

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson("/api/admin/content-reviews/auction/{$auction->public_id}/run")
            ->assertCreated()
            ->assertJsonPath('data.trigger', ReviewTrigger::AdminManual->value)
            ->assertJsonPath('data.status', ContentReviewStatus::Queued->value);

        $this->assertSame(2, ContentReview::count());
        $this->assertSame(ContentReviewStatus::Superseded, $first->refresh()->status);
        $this->assertSame(1, ContentReview::whereNotNull('current_marker')->count());
    }

    public function test_run_is_refused_while_the_mode_is_manual(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Manual);

        $auction = $this->submitForReview();

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson("/api/admin/content-reviews/auction/{$auction->public_id}/run")
            ->assertStatus(409)
            ->assertJsonPath('code', 'review_manual_mode');
    }

    public function test_retry_creates_a_new_attempt_and_keeps_the_failed_attempt_in_the_history(): void
    {
        $review = $this->failedReview();

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson("/api/admin/content-reviews/{$review->public_id}/retry")
            ->assertCreated()
            ->assertJsonPath('data.trigger', ReviewTrigger::AdminRetry->value);

        $this->assertSame(2, ContentReview::count());
        $this->assertSame(ContentReviewStatus::Failed, $review->refresh()->status);
        $this->assertNull($review->refresh()->current_marker);
        $this->assertSame(1, ContentReview::whereNotNull('current_marker')->count());
    }

    public function test_retry_is_refused_for_a_completed_review(): void
    {
        $review = $this->completedReview();

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson("/api/admin/content-reviews/{$review->public_id}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'review_not_retryable');
    }

    public function test_retry_is_refused_for_a_cancelled_review(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->submitForReview();

        $review = ContentReview::firstOrFail();
        app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Cancelled->value,
        ]);

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson("/api/admin/content-reviews/{$review->public_id}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'review_not_retryable');
    }

    public function test_retry_is_refused_once_the_content_changed(): void
    {
        $review = $this->failedReview();
        $auction = \App\Models\Auction\Auction::findOrFail($review->subject_id);
        $auction->forceFill(['title' => 'A different title for the very same auction'])->save();

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson("/api/admin/content-reviews/{$review->public_id}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'review_stale');
    }

    public function test_cancel_stops_a_queued_review(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->submitForReview();

        $review = ContentReview::firstOrFail();

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson("/api/admin/content-reviews/{$review->public_id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', ContentReviewStatus::Cancelled->value);

        $this->assertNull($review->refresh()->current_marker);
    }

    public function test_cancel_is_refused_once_the_review_has_been_decided(): void
    {
        $review = $this->completedReview();

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson("/api/admin/content-reviews/{$review->public_id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'review_already_decided');
    }

    public function test_force_manual_cancels_pending_work_and_blocks_a_later_ai_decision(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAutomatic);

        $auction = $this->submitForReview();
        $review = ContentReview::firstOrFail();

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson("/api/admin/content-reviews/auction/{$auction->public_id}/force-manual")
            ->assertOk();

        $review->refresh();
        $this->assertSame(ContentReviewStatus::Cancelled, $review->status);
        $this->assertNull($review->current_marker);
        $this->assertNotNull($review->decided_at);

        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        $this->assertSame(0, $this->fakeProvider()->calls());
        $this->assertSame(ContentReviewStatus::Cancelled, $review->refresh()->status);
        $this->assertSame(\App\Domain\Auction\Enums\AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_the_provider_health_endpoint_never_reveals_the_api_key(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-super-secret-value');
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow, ['provider' => 'anthropic']);

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson('/api/admin/content-review/health')
            ->assertOk()
            ->assertJsonPath('data.provider', 'anthropic')
            ->assertJsonPath('data.configured', true)
            ->assertJsonStructure(['data' => [
                'enabled', 'configured', 'provider', 'model', 'mode',
                'circuit' => ['state', 'failure_count', 'open_until'],
                'queue' => ['max_concurrent', 'queued', 'running'],
                'budget' => ['daily', 'monthly'],
            ]]);

        $this->assertStringNotContainsString('sk-ant-super-secret-value', $response->getContent());
    }

    public function test_the_provider_health_endpoint_makes_no_provider_call(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson('/api/admin/content-review/health')
            ->assertOk();

        $this->assertSame(0, $this->fakeProvider()->calls());
    }

    public function test_the_provider_test_endpoint_reports_a_failure_without_leaking_internals(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->fakeProvider()->failWith(\App\Domain\ContentReview\Enums\ContentReviewErrorCode::ProviderUnavailable);

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson('/api/admin/content-review/provider/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error_code', 'provider_unavailable');

        $this->assertArrayNotHasKey('raw', (array) $response->json('data'));
    }

    public function test_the_provider_test_endpoint_is_rate_limited(): void
    {
        config()->set('content_review.provider_test.rate_limit_per_minute', 1);
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        $admin = $this->fullyPermittedAdmin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/content-review/provider/test')->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/content-review/provider/test')->assertStatus(429);
    }

    private function completedReview(): ContentReview
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->submitForReview();

        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = ContentReview::firstOrFail();
        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        return $review->refresh();
    }

    private function failedReview(): ContentReview
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->submitForReview();

        $review = ContentReview::firstOrFail();

        return app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Failed->value,
            'error_code' => \App\Domain\ContentReview\Enums\ContentReviewErrorCode::ProviderUnavailable->value,
            'requires_human_review' => true,
            'completed_at' => now(),
            'decided_at' => now(),
        ]);
    }

    private function fakeProvider(): FakeContentReviewProvider
    {
        return app(FakeContentReviewProvider::class);
    }
}
