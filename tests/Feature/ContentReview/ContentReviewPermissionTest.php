<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\Auction;
use App\Models\ContentReview\ContentReview;
use App\Models\User;
use App\Services\ContentReview\Actions\ProcessContentReviewAction;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class ContentReviewPermissionTest extends TestCase
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

    public function test_no_content_review_permission_is_configured_anywhere(): void
    {
        $flat = json_encode(config('content_review'), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('content_review.view', $flat);
        $this->assertStringNotContainsString('admin_permissions', $flat);
        $this->assertNull(config('content_review.admin_permissions'));
        $this->assertNull(config('content_review.role_admin_permissions'));
    }

    public function test_a_plain_admin_reaches_every_content_review_endpoint(): void
    {
        $review = $this->completedReview();
        $auction = Auction::findOrFail($review->subject_id);
        $admin = $this->admin();

        foreach ([
            "/api/admin/content-reviews/{$review->public_id}",
            "/api/admin/content-reviews/auction/{$auction->public_id}",
            "/api/admin/content-reviews/auction/{$auction->public_id}/current",
            '/api/admin/content-review/settings',
            '/api/admin/content-review/settings/versions',
            '/api/admin/content-review/policies',
            '/api/admin/content-review/policies/active',
            '/api/admin/content-review/health',
            '/api/admin/content-review/metrics',
        ] as $path) {
            $this->actingAs($admin, 'sanctum')->getJson($path)->assertOk();
        }
    }

    public function test_a_plain_admin_may_run_every_write_action(): void
    {
        $review = $this->completedReview();
        $auction = Auction::findOrFail($review->subject_id);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/content-review/provider/test')
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/content-reviews/auction/{$auction->public_id}/force-manual")
            ->assertOk();
    }

    public function test_a_seller_reaches_no_content_review_endpoint(): void
    {
        $review = $this->completedReview();
        $seller = $this->seller();

        foreach ([
            "/api/admin/content-reviews/{$review->public_id}",
            '/api/admin/content-review/settings',
            '/api/admin/content-review/policies',
            '/api/admin/content-review/health',
            '/api/admin/content-review/metrics',
        ] as $path) {
            $this->actingAs($seller, 'sanctum')->getJson($path)->assertForbidden();
        }
    }

    public function test_a_seller_cannot_write_through_an_admin_endpoint(): void
    {
        $review = $this->completedReview(ReviewMode::AiAssisted);
        $auction = Auction::findOrFail($review->subject_id);
        $seller = $this->seller();

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/admin/content-reviews/{$review->public_id}/decide", ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/admin/content-reviews/auction/{$auction->public_id}/force-manual")
            ->assertForbidden();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/admin/content-review/provider/test')
            ->assertForbidden();
    }

    public function test_an_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/admin/content-review/health')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $this->getJson('/api/admin/content-review/metrics')->assertUnauthorized();
    }

    public function test_every_admin_sees_the_ai_review_block_on_the_auction_payload(): void
    {
        $review = $this->completedReview();
        $auction = Auction::findOrFail($review->subject_id);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/admin/auctions/{$auction->public_id}")
            ->assertOk()
            ->assertJsonPath('data.ai_review.mode', ReviewMode::Shadow->value)
            ->assertJsonPath('data.ai_review.current.recommendation', 'approve')
            ->assertJsonPath('data.ai_review.current.confidence', 96);
    }

    public function test_every_admin_receives_the_technical_and_cost_blocks(): void
    {
        $review = $this->completedReview();

        $data = (array) $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('technical', $data);
        $this->assertArrayHasKey('cost', $data);
        $this->assertSame('fake', $data['technical']['provider']);
        $this->assertSame('USD', $data['cost']['currency']);
    }

    public function test_every_admin_receives_the_budget_and_cost_blocks_on_health_and_metrics(): void
    {
        $this->completedReview();
        $admin = $this->admin();

        $health = (array) $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/content-review/health')->assertOk()->json('data');
        $this->assertArrayHasKey('budget', $health);

        $metrics = (array) $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/content-review/metrics')->assertOk()->json('data');
        $this->assertArrayHasKey('cost', $metrics);
    }

    public function test_available_actions_follow_the_review_state_not_the_caller(): void
    {
        $review = $this->completedReview();

        $plain = $this->availableActions($this->admin(), $review);
        $reviewer = $this->availableActions($this->auctionReviewer(), $review);

        $this->assertSame(['run', 'force_manual'], $plain);
        $this->assertSame($plain, $reviewer);
    }

    public function test_confirm_and_override_appear_together_on_an_assisted_recommendation(): void
    {
        $review = $this->completedReview(ReviewMode::AiAssisted);

        $this->assertSame(
            ['run', 'force_manual', 'confirm', 'override'],
            $this->availableActions($this->admin(), $review)
        );
    }

    public function test_a_shadow_recommendation_offers_neither_confirm_nor_override(): void
    {
        $review = $this->completedReview();

        $actions = $this->availableActions($this->admin(), $review);

        $this->assertNotContains('confirm', $actions);
        $this->assertNotContains('override', $actions);
    }

    public function test_the_api_never_returns_the_raw_provider_response_or_the_api_key(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-leak-canary');
        $review = $this->completedReview();
        $auction = Auction::findOrFail($review->subject_id);
        $admin = $this->admin();

        foreach ([
            "/api/admin/content-reviews/{$review->public_id}",
            "/api/admin/content-reviews/auction/{$auction->public_id}",
            "/api/admin/content-reviews/auction/{$auction->public_id}/current",
            '/api/admin/content-review/health',
            '/api/admin/content-review/metrics',
            '/api/admin/content-review/settings',
            '/api/admin/content-review/policies',
            "/api/admin/auctions/{$auction->public_id}",
        ] as $path) {
            $content = $this->actingAs($admin, 'sanctum')->getJson($path)->assertOk()->getContent();

            $this->assertStringNotContainsString('sk-ant-leak-canary', $content, $path);
            $this->assertStringNotContainsString('api_key', $content, $path);
            $this->assertStringNotContainsString('raw_response', $content, $path);
            $this->assertStringNotContainsString('policy_instructions', $content, $path);
            $this->assertStringNotContainsString('chain_of_thought', $content, $path);
            $this->assertStringNotContainsString($review->content_hash, $content, $path);
        }
    }

    public function test_the_review_payload_never_exposes_image_storage_paths(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $auction = $this->draftAuction();
        $media = $this->attachMedia($auction);
        $this->submitForReview($auction);

        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = ContentReview::firstOrFail();
        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        $content = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($media->path, $content);
        $this->assertStringNotContainsString('"disk"', $content);
    }

    private function availableActions(User $user, ContentReview $review): array
    {
        return (array) $this->actingAs($user, 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk()
            ->json('data.available_actions');
    }

    private function completedReview(ReviewMode $mode = ReviewMode::Shadow): ContentReview
    {
        $this->publishPolicy();
        $this->publishSettings($mode);
        $this->submitForReview();

        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = ContentReview::firstOrFail();
        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        return $review->refresh();
    }

    private function fakeProvider(): FakeContentReviewProvider
    {
        return app(FakeContentReviewProvider::class);
    }
}
