<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ImageCheckVerdict;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\Domain\ContentReview\Enums\StructuredOutputStrategy;
use App\Models\Auction\AuctionMedia;
use App\Models\ContentReview\ContentReviewImageCheck;
use App\Repositories\ContentReview\ContentReviewImageCheckRepository;
use App\Services\ContentReview\Actions\RunContentReviewAction;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class ImageCacheTest extends TestCase
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
    }

    public function test_a_first_analysis_stores_one_cache_row_per_image(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted, images: 2);
        $this->fakeProvider()->respondWith($this->cleanResultPayload(2));

        $review = $this->processActiveReview($auction);

        $this->assertSame(2, ContentReviewImageCheck::count());
        $this->assertSame(2, $review->image_review['counts']['analyzed']);
        $this->assertSame(0, $review->image_review['counts']['cache_hits']);
        $this->assertSame(2, (int) $review->images_analyzed);

        $row = ContentReviewImageCheck::firstOrFail();

        $this->assertSame('fake', $row->provider);
        $this->assertSame('claude-sonnet-5', $row->model);
        $this->assertSame(ImageCheckVerdict::Clean, $row->verdict);
        $this->assertSame(ReviewRiskLevel::Low, $row->risk_level);
        $this->assertSame(1, (int) $row->result_schema_version);
        $this->assertGreaterThan(0, (int) $row->policy_version);
    }

    public function test_a_second_review_of_the_same_content_reuses_the_cached_check(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        $this->processActiveReview($auction);

        $this->assertSame(1, ContentReviewImageCheck::count());
        $this->assertCount(1, (array) $this->fakeProvider()->lastRequest()->images);

        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        app(RunContentReviewAction::class)->execute(
            ReviewableSubjectType::Auction,
            (int) $auction->id,
            (int) $this->fullyPermittedAdmin()->id,
        );

        $second = $this->processActiveReview($auction);

        $this->assertSame(1, ContentReviewImageCheck::count());
        $this->assertSame([], (array) $this->fakeProvider()->lastRequest()->images);
        $this->assertSame(1, $second->image_review['counts']['cache_hits']);
        $this->assertSame(0, $second->image_review['counts']['analyzed']);
        $this->assertSame(0, $second->image_review['counts']['sent']);
        $this->assertTrue($second->image_review['checks'][0]['from_cache']);
    }

    public function test_the_same_image_on_another_subject_is_not_analyzed_again(): void
    {
        $bytes = $this->sampleJpegBytes();

        $first = $this->reviewedAuction(ReviewMode::AiAssisted, mediaOverrides: [['bytes' => $bytes]]);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $this->processActiveReview($first);

        $second = $this->reviewedAuction(
            ReviewMode::AiAssisted,
            mediaOverrides: [['bytes' => $bytes]],
            publishPolicy: false,
        );
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = $this->processActiveReview($second);

        $this->assertSame(1, ContentReviewImageCheck::count());
        $this->assertSame(1, $review->image_review['counts']['cache_hits']);
        $this->assertSame([], (array) $this->fakeProvider()->lastRequest()->images);
    }

    public function test_a_different_model_never_reuses_an_incompatible_result(): void
    {
        $bytes = $this->sampleJpegBytes();

        $first = $this->reviewedAuction(ReviewMode::AiAssisted, mediaOverrides: [['bytes' => $bytes]]);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $this->processActiveReview($first);

        $second = $this->reviewedAuction(
            ReviewMode::AiAssisted,
            mediaOverrides: [['bytes' => $bytes]],
            publishPolicy: false,
        );
        $this->publishSettings(ReviewMode::AiAssisted, ['model' => 'claude-haiku-4-5']);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = $this->processActiveReview($second);

        $this->assertSame(2, ContentReviewImageCheck::count());
        $this->assertSame(0, $review->image_review['counts']['cache_hits']);
        $this->assertSame(1, $review->image_review['counts']['analyzed']);
        $this->assertSame(1, ContentReviewImageCheck::where('model', 'claude-haiku-4-5')->count());
    }

    public function test_a_new_policy_version_never_reuses_an_incompatible_result(): void
    {
        $bytes = $this->sampleJpegBytes();

        $first = $this->reviewedAuction(ReviewMode::AiAssisted, mediaOverrides: [['bytes' => $bytes]]);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $this->processActiveReview($first);

        $second = $this->reviewedAuction(ReviewMode::AiAssisted, mediaOverrides: [['bytes' => $bytes]]);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = $this->processActiveReview($second);

        $this->assertSame(2, ContentReviewImageCheck::count());
        $this->assertSame(0, $review->image_review['counts']['cache_hits']);
        $this->assertSame(
            2,
            ContentReviewImageCheck::query()->distinct()->count('policy_version'),
            'A republished policy must not reuse an image result produced under the previous version.'
        );
    }

    public function test_changed_image_bytes_invalidate_the_cache(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $this->processActiveReview($auction);

        $media = AuctionMedia::where('auction_id', $auction->id)->firstOrFail();
        Storage::disk('public')->put((string) $media->path, $this->sampleJpegBytes().'-mutated');

        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        app(RunContentReviewAction::class)->execute(
            ReviewableSubjectType::Auction,
            (int) $auction->id,
            (int) $this->fullyPermittedAdmin()->id,
        );

        $review = $this->processActiveReview($auction);

        $this->assertSame(2, ContentReviewImageCheck::count());
        $this->assertSame(0, $review->image_review['counts']['cache_hits']);
        $this->assertSame(1, $review->image_review['counts']['analyzed']);
    }

    public function test_a_repeated_insert_of_the_same_fingerprint_creates_no_duplicate(): void
    {
        $repository = app(ContentReviewImageCheckRepository::class);
        $fingerprint = hash('sha256', 'stable-image-bytes');

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $row = $repository->remember(
                $fingerprint,
                'fake',
                'claude-sonnet-5',
                3,
                1,
                ImageCheckVerdict::Clean,
                ReviewRiskLevel::Low,
                [],
                null,
            );

            $this->assertNotNull($row);
        }

        $this->assertSame(1, $repository->countForFingerprint($fingerprint));
    }

    public function test_an_unreadable_image_fails_safe_without_a_cache_row(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted, mediaOverrides: [['unreadable' => true]]);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        $review = $this->processActiveReview($auction);

        $this->assertSame(0, ContentReviewImageCheck::count());
        $this->assertSame(1, $review->image_review['counts']['failed']);
        $this->assertSame('image_unreadable', $review->image_review['failures'][0]['failure_code']);
        $this->assertSame([], (array) $this->fakeProvider()->lastRequest()->images);
    }

    public function test_a_corrupt_image_fails_safe(): void
    {
        $auction = $this->reviewedAuction(
            ReviewMode::AiAssisted,
            mediaOverrides: [['bytes' => 'this is not an image at all']],
        );
        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        $review = $this->processActiveReview($auction);

        $this->assertSame(0, ContentReviewImageCheck::count());
        $this->assertSame('image_corrupt', $review->image_review['failures'][0]['failure_code']);
    }

    public function test_an_unsupported_mime_type_fails_safe(): void
    {
        $auction = $this->reviewedAuction(
            ReviewMode::AiAssisted,
            mediaOverrides: [['mime_type' => 'image/gif', 'extension' => 'gif']],
        );
        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        $review = $this->processActiveReview($auction);

        $this->assertSame(0, ContentReviewImageCheck::count());
        $this->assertSame('unsupported_mime', $review->image_review['failures'][0]['failure_code']);
    }

    public function test_a_declared_mime_that_does_not_match_the_bytes_fails_safe(): void
    {
        $auction = $this->reviewedAuction(
            ReviewMode::AiAssisted,
            mediaOverrides: [['bytes' => $this->samplePngBytes()]],
        );
        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        $review = $this->processActiveReview($auction);

        $this->assertSame(0, ContentReviewImageCheck::count());
        $this->assertSame('mime_mismatch', $review->image_review['failures'][0]['failure_code']);
    }

    public function test_the_image_limit_is_respected_and_selection_is_deterministic(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted, ['max_images' => 2], images: 4);
        $this->fakeProvider()->respondWith($this->cleanResultPayload(2));

        $review = $this->processActiveReview($auction);

        $this->assertSame(2, $review->image_review['counts']['selected']);
        $this->assertCount(2, (array) $this->fakeProvider()->lastRequest()->images);
        $this->assertSame(4, $review->image_review['counts']['total']);

        $selected = array_map(
            static fn (array $image): string => (string) $image['ref'],
            (array) $this->fakeProvider()->lastRequest()->images
        );

        $this->assertSame(['img-1', 'img-2'], $selected);

        $lowestSortOrders = AuctionMedia::where('auction_id', $auction->id)
            ->orderBy('sort_order')
            ->limit(2)
            ->pluck('sort_order')
            ->all();

        $this->assertSame([0, 1], array_map('intval', $lowestSortOrders));
    }

    public function test_the_original_image_file_is_never_modified(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted);
        $media = AuctionMedia::where('auction_id', $auction->id)->firstOrFail();
        $before = (string) Storage::disk('public')->get((string) $media->path);

        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $this->processActiveReview($auction);

        $this->assertSame($before, (string) Storage::disk('public')->get((string) $media->path));
        $this->assertSame(hash('sha256', $before), hash('sha256', (string) Storage::disk('public')->get((string) $media->path)));
    }

    public function test_the_cache_stores_no_image_bytes_and_no_storage_path(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted);
        $media = AuctionMedia::where('auction_id', $auction->id)->firstOrFail();
        $bytes = $this->sampleJpegBytes();

        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = $this->processActiveReview($auction);

        $serialized = json_encode([
            ContentReviewImageCheck::firstOrFail()->toArray(),
            $review->image_review,
        ], JSON_UNESCAPED_UNICODE);

        $this->assertIsString($serialized);
        $this->assertStringNotContainsString((string) $media->path, $serialized);
        $this->assertStringNotContainsString(base64_encode($bytes), $serialized);
        $this->assertStringNotContainsString('auction-media/', $serialized);
        $this->assertStringNotContainsString($bytes, $serialized);
        $this->assertSame(
            hash('sha256', $bytes),
            (string) ContentReviewImageCheck::firstOrFail()->image_sha256,
            'The cache key must be the image content fingerprint.'
        );
    }

    public function test_a_check_for_an_image_that_was_never_attached_is_ignored(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted);
        $this->fakeProvider()->respondWith(array_replace($this->cleanResultPayload(), [
            'image_checks' => $this->imageCheckPayload(4),
        ]));

        $review = $this->processActiveReview($auction);

        $this->assertSame(1, ContentReviewImageCheck::count());
        $this->assertSame(1, $review->image_review['counts']['analyzed']);
        $this->assertCount(1, $review->image_review['checks']);
    }

    public function test_an_image_the_model_never_scored_is_reported_as_unscreened(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted, images: 2);
        $this->fakeProvider()->respondWith(array_replace($this->cleanResultPayload(), [
            'image_checks' => $this->imageCheckPayload(1),
        ]));

        $review = $this->processActiveReview($auction);

        $this->assertSame(1, ContentReviewImageCheck::count());
        $this->assertSame(1, $review->image_review['counts']['unscreened']);
    }

    public function test_disabling_image_analysis_sends_no_image_and_stores_no_cache(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted, ['analyze_images' => false]);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        $review = $this->processActiveReview($auction);

        $this->assertSame(0, ContentReviewImageCheck::count());
        $this->assertFalse($review->image_review['analysis_enabled']);
        $this->assertSame([], (array) $this->fakeProvider()->lastRequest()->images);
    }

    public function test_a_model_that_cannot_read_images_is_never_sent_one(): void
    {
        config()->set('content_review.providers.fake.models.claude-sonnet-5.vision', false);

        $auction = $this->reviewedAuction(ReviewMode::AiAssisted);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        $review = $this->processActiveReview($auction);

        $this->assertSame(0, ContentReviewImageCheck::count());
        $this->assertSame(0, (int) $review->images_analyzed);
        $this->assertFalse($review->image_review['analysis_enabled']);
        $this->assertSame([], (array) $this->fakeProvider()->lastRequest()->images);
    }

    public function test_the_model_capabilities_travel_with_the_provider_request(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());

        $this->processActiveReview($auction);

        $descriptor = $this->fakeProvider()->lastRequest()->modelDescriptor;

        $this->assertNotNull($descriptor);
        $this->assertSame('claude-sonnet-5', $descriptor->id);
        $this->assertSame(StructuredOutputStrategy::Tool, $descriptor->structured);
    }
}
