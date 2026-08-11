<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionMedia;
use App\Models\ContentReview\ContentReview;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class ContentReviewAutomationApiTest extends TestCase
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

    public function test_the_review_endpoint_reports_why_a_subject_is_not_eligible(): void
    {
        $review = $this->analysed(['allowed_category_ids' => []]);

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk();

        $this->assertFalse($response->json('data.automation.is_eligible'));
        $this->assertContains('category_not_allowed', $response->json('data.automation.reasons'));
        $this->assertNotContains('category_not_allowed', $response->json('data.automation.reason_labels'));
        $this->assertSame(ReviewMode::AiAutomatic->value, $response->json('data.automation.mode'));
    }

    public function test_the_current_endpoint_reports_an_eligible_subject(): void
    {
        $review = $this->analysed();
        $auction = Auction::whereKey((int) $review->subject_id)->firstOrFail();

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/auction/{$auction->public_id}/current")
            ->assertOk();

        $this->assertTrue($response->json('data.automation.is_eligible'));
        $this->assertSame([], $response->json('data.automation.reasons'));
        $this->assertSame([], $response->json('data.automation.approval_blockers'));
    }

    public function test_a_flagged_image_is_reported_as_an_approval_blocker(): void
    {
        $review = $this->analysed(imageVerdict: 'flagged');

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk();

        $this->assertSame(['image_flagged'], $response->json('data.automation.approval_blockers'));
        $this->assertSame('flagged', $response->json('data.image_analysis.checks.0.verdict'));
        $this->assertNotNull($response->json('data.image_analysis.checks.0.verdict_label'));
    }

    public function test_the_image_summary_reports_totals_analyzed_and_cache_hits(): void
    {
        $review = $this->analysed(images: 2);

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk();

        $this->assertTrue($response->json('data.image_analysis.analysis_enabled'));
        $this->assertSame(2, $response->json('data.image_analysis.total'));
        $this->assertSame(2, $response->json('data.image_analysis.selected'));
        $this->assertSame(2, $response->json('data.image_analysis.analyzed'));
        $this->assertSame(0, $response->json('data.image_analysis.cache_hits'));
        $this->assertSame(0, $response->json('data.image_analysis.failed'));
        $this->assertCount(2, $response->json('data.image_analysis.checks'));
        $this->assertFalse($response->json('data.image_analysis.checks.0.from_cache'));
    }

    public function test_a_failed_image_is_reported_with_a_stable_code(): void
    {
        $review = $this->analysed(mediaOverrides: [['unreadable' => true]]);

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk();

        $this->assertSame(1, $response->json('data.image_analysis.failed'));
        $this->assertSame('image_unreadable', $response->json('data.image_analysis.failures.0.failure_code'));
        $this->assertNotNull($response->json('data.image_analysis.failures.0.failure_label'));
    }

    public function test_the_automation_payload_never_leaks_a_path_a_fingerprint_or_image_bytes(): void
    {
        $review = $this->analysed();
        $media = AuctionMedia::where('auction_id', (int) $review->subject_id)->firstOrFail();
        $bytes = $this->sampleJpegBytes();

        $body = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson("/api/admin/content-reviews/{$review->public_id}")
            ->assertOk()
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString((string) $media->path, $body);
        $this->assertStringNotContainsString('auction-media/', $body);
        $this->assertStringNotContainsString(hash('sha256', $bytes), $body);
        $this->assertStringNotContainsString((string) $review->content_hash, $body);
        $this->assertStringNotContainsString(base64_encode($bytes), $body);
    }

    public function test_the_queue_list_carries_no_automation_block(): void
    {
        $this->analysed();

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson('/api/admin/auctions?per_page=15')
            ->assertOk();

        $this->assertNull($response->json('data.0.ai_review.current.automation'));
    }

    private function analysed(
        array $automationOverrides = [],
        int $images = 1,
        array $mediaOverrides = [],
        string $imageVerdict = 'clean',
    ): ContentReview {
        $auction = $this->reviewedAuction(
            ReviewMode::AiAutomatic,
            [],
            $automationOverrides,
            $images,
            $mediaOverrides,
        );

        $this->fakeProvider()->respondWith(array_replace($this->cleanResultPayload(), [
            'requires_human_review' => true,
            'image_checks' => $this->imageCheckPayload($images, $imageVerdict),
        ]));

        return $this->processActiveReview($auction);
    }
}
