<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\ContentReview\ContentReview;
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

    /**
     * The configured fallback is what makes an admin able to see reviews at all, so
     * these tests clear it and grant every permission explicitly. The fallback itself
     * is pinned by test_the_role_admin_fallback_grants_exactly_view_run_and_cancel.
     */
    private function withoutRoleFallback(): void
    {
        config()->set('content_review.role_admin_permissions', []);
    }

    public function test_the_role_admin_fallback_grants_exactly_view_run_and_cancel(): void
    {
        $this->assertSame(
            ['content_review.view', 'content_review.run', 'content_review.cancel'],
            (array) config('content_review.role_admin_permissions')
        );
    }

    public function test_a_plain_admin_holds_only_the_three_default_permissions(): void
    {
        $review = $this->completedReview();
        $auction = $review->subject_id;
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/content-review/settings')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/content-review/policies')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/content-reviews/auction/{$auction}/force-manual")
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/content-review/provider/test')
            ->assertForbidden();
    }

    public function test_a_non_admin_reaches_no_content_review_endpoint(): void
    {
        $review = $this->completedReview();
        $seller = $this->seller();

        foreach ([
            "/api/admin/content-reviews/{$review->public_id}",
            '/api/admin/content-review/settings',
            '/api/admin/content-review/policies',
            '/api/admin/content-review/health',
        ] as $path) {
            $this->actingAs($seller, 'sanctum')->getJson($path)->assertForbidden();
        }
    }

    public function test_an_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/admin/content-review/health')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_an_admin_without_the_view_permission_never_sees_the_ai_review_block(): void
    {
        $review = $this->completedReview();
        $auction = \App\Models\Auction\Auction::findOrFail($review->subject_id);

        $this->withoutRoleFallback();
        $withoutContentReview = $this->admin(['auction.review', 'auction.approve']);

        $response = $this->actingAs($withoutContentReview, 'sanctum')
            ->getJson("/api/admin/auctions/{$auction->public_id}")
            ->assertOk();

        $this->assertArrayNotHasKey('ai_review', (array) $response->json('data'));
    }

    public function test_an_admin_with_the_view_permission_sees_the_redacted_ai_review_block(): void
    {
        $review = $this->completedReview();
        $auction = \App\Models\Auction\Auction::findOrFail($review->subject_id);
        $this->withoutRoleFallback();

        $response = $this->actingAs($this->auctionReviewer(['content_review.view']), 'sanctum')
            ->getJson("/api/admin/auctions/{$auction->public_id}")
            ->assertOk()
            ->assertJsonPath('data.ai_review.mode', ReviewMode::Shadow->value)
            ->assertJsonPath('data.ai_review.current.recommendation', 'approve')
            ->assertJsonPath('data.ai_review.current.confidence', 96);

        $block = (array) $response->json('data.ai_review.current');

        $this->assertArrayNotHasKey('technical', $block);
        $this->assertArrayNotHasKey('cost', $block);
    }

    public function test_technical_and_cost_blocks_need_their_own_permissions(): void
    {
        $review = $this->completedReview();

        $this->withoutRoleFallback();

        $viewOnly = $this->admin(['content_review.view']);
        $withTechnical = $this->admin(['content_review.view', 'content_review.technical.view']);
        $withCosts = $this->admin(['content_review.view', 'content_review.costs.view']);

        $plain = (array) $this->actingAs($viewOnly, 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")->json('data');
        $this->assertArrayNotHasKey('technical', $plain);
        $this->assertArrayNotHasKey('cost', $plain);

        $technical = (array) $this->actingAs($withTechnical, 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")->json('data');
        $this->assertArrayHasKey('technical', $technical);
        $this->assertArrayNotHasKey('cost', $technical);

        $costs = (array) $this->actingAs($withCosts, 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")->json('data');
        $this->assertArrayHasKey('cost', $costs);
        $this->assertArrayNotHasKey('technical', $costs);
    }

    public function test_available_actions_follow_the_caller_permissions(): void
    {
        $review = $this->completedReview();
        $this->withoutRoleFallback();

        $viewOnly = $this->admin(['content_review.view']);
        $this->assertSame([], $this->availableActions($viewOnly, $review));

        $runner = $this->admin(['content_review.view', 'content_review.run']);
        $this->assertSame(['run'], $this->availableActions($runner, $review));

        $full = $this->fullyPermittedAdmin();
        $this->assertSame(['run', 'force_manual', 'override'], $this->availableActions($full, $review));
    }

    public function test_the_api_never_returns_the_raw_provider_response_or_the_api_key(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-leak-canary');
        $review = $this->completedReview();
        $auction = \App\Models\Auction\Auction::findOrFail($review->subject_id);
        $admin = $this->fullyPermittedAdmin();

        foreach ([
            "/api/admin/content-reviews/{$review->public_id}",
            "/api/admin/content-reviews/auction/{$auction->public_id}",
            "/api/admin/content-reviews/auction/{$auction->public_id}/current",
            '/api/admin/content-review/health',
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

        $content = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($media->path, $content);
        $this->assertStringNotContainsString('"disk"', $content);
    }

    /**
     * @return array<int, string>
     */
    private function availableActions(\App\Models\User $user, ContentReview $review): array
    {
        return (array) $this->actingAs($user, 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk()
            ->json('data.available_actions');
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

    private function fakeProvider(): FakeContentReviewProvider
    {
        return app(FakeContentReviewProvider::class);
    }
}
