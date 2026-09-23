<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewDecisionRepository;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewAlertMonitor;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use App\Services\ContentReview\Support\ContentReviewWorkerHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class ContentReviewMetricsApiTest extends TestCase
{
    use BuildsContentReviewFixtures;

    private const PATH = '/api/admin/content-review/metrics?market=jo';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config()->set('content_review.enabled', true);
        config()->set('content_review.metrics.cache_seconds', 1);
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        app(FakeContentReviewProvider::class)->reset();
        app(ContentReviewCircuitBreaker::class)->reset();
        app(ContentReviewAlertMonitor::class)->reset();
    }

    public function test_any_authenticated_admin_reaches_the_endpoint(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->getJson(self::PATH)->assertOk();
        $this->actingAs($this->admin(['auction.review']), 'sanctum')->getJson(self::PATH)->assertOk();
    }

    public function test_a_seller_can_never_reach_the_endpoint(): void
    {
        $this->getJson(self::PATH)->assertUnauthorized();
        $this->actingAs($this->seller(), 'sanctum')->getJson(self::PATH)->assertForbidden();
    }

    public function test_the_volume_block_counts_every_status(): void
    {
        $this->seedReviews([
            ['status' => ContentReviewStatus::Completed, 'outcome' => ContentReviewOutcome::AdvisoryOnly],
            ['status' => ContentReviewStatus::Completed, 'outcome' => ContentReviewOutcome::AutoApproved],
            ['status' => ContentReviewStatus::Failed, 'error_code' => ContentReviewErrorCode::ProviderTimeout],
            ['status' => ContentReviewStatus::Cancelled, 'outcome' => ContentReviewOutcome::NoDecision],
            ['status' => ContentReviewStatus::Superseded, 'outcome' => ContentReviewOutcome::NoDecision],
            ['status' => ContentReviewStatus::Queued],
        ]);

        $data = $this->metrics();

        $this->assertSame(6, $data['volume']['total']);
        $this->assertSame(2, $data['volume']['completed']);
        $this->assertSame(1, $data['volume']['failed']);
        $this->assertSame(1, $data['volume']['cancelled']);
        $this->assertSame(1, $data['volume']['superseded']);
        $this->assertSame(1, $data['volume']['queued']);
        $this->assertSame(2, $data['outcomes']['pending']);
        $this->assertSame(1, $data['outcomes']['auto_approved']);
        $this->assertSame(1, $data['outcomes']['advisory_only']);
    }

    public function test_the_provider_rates_are_computed_from_completed_and_failed_attempts(): void
    {
        $this->seedReviews([
            ['status' => ContentReviewStatus::Completed],
            ['status' => ContentReviewStatus::Completed],
            ['status' => ContentReviewStatus::Completed],
            ['status' => ContentReviewStatus::Failed, 'error_code' => ContentReviewErrorCode::InvalidStructuredOutput],
        ]);

        $data = $this->metrics();

        $this->assertSame(4, $data['provider']['calls']);
        $this->assertSame(3, $data['provider']['succeeded']);
        $this->assertSame(1, $data['provider']['failed']);
        $this->assertSame(1, $data['provider']['invalid_structured_output']);
        $this->assertSame(75, $data['rates']['provider_success_percent']);
        $this->assertSame(25, $data['rates']['provider_failure_percent']);
        $this->assertSame(25, $data['rates']['invalid_output_percent']);
    }

    public function test_a_guard_failure_is_reported_separately_from_a_provider_failure(): void
    {
        $this->seedReviews([
            ['status' => ContentReviewStatus::Completed],
            ['status' => ContentReviewStatus::Failed, 'error_code' => ContentReviewErrorCode::CircuitOpen],
            ['status' => ContentReviewStatus::Failed, 'error_code' => ContentReviewErrorCode::BudgetExhausted],
        ]);

        $data = $this->metrics();

        $this->assertSame(1, $data['provider']['calls']);
        $this->assertSame(0, $data['provider']['failed']);
        $this->assertSame(1, $data['provider']['guard_blocked']['circuit_open']);
        $this->assertSame(1, $data['provider']['guard_blocked']['budget_exhausted']);
    }

    public function test_the_percentiles_are_exact(): void
    {
        $durations = [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000];

        $this->seedReviews(array_map(
            static fn (int $ms): array => ['status' => ContentReviewStatus::Completed, 'duration_ms' => $ms, 'queue_delay_ms' => $ms * 2],
            $durations
        ));

        $data = $this->metrics();

        $this->assertSame(10, $data['latency']['duration_sample']);
        $this->assertSame(550, $data['latency']['average_duration_ms']);
        $this->assertSame(500, $data['latency']['p50_duration_ms']);
        $this->assertSame(900, $data['latency']['p95_duration_ms']);
        $this->assertSame(1000, $data['latency']['p50_queue_delay_ms']);
        $this->assertSame(1800, $data['latency']['p95_queue_delay_ms']);
    }

    public function test_override_and_agreement_rates_come_from_the_decision_rows(): void
    {
        $review = $this->seedReviews([['status' => ContentReviewStatus::Completed]])[0];
        $decisions = app(ContentReviewDecisionRepository::class);
        $admin = $this->admin();

        foreach ([DecisionRelation::Confirmed, DecisionRelation::Confirmed, DecisionRelation::Confirmed, DecisionRelation::Overridden] as $relation) {
            $decisions->record(
                (int) $review->id,
                ReviewableSubjectType::Auction,
                (int) $review->subject_id,
                ContentReviewDecisionType::Approved,
                DecisionActorType::Admin,
                (int) $admin->id,
                $relation,
                ReviewRecommendation::Approve,
                90,
                null,
            );
        }

        $data = $this->metrics();

        $this->assertSame(4, $data['human']['decisions']);
        $this->assertSame(3, $data['human']['confirmations']);
        $this->assertSame(1, $data['human']['overrides']);
        $this->assertSame(25, $data['rates']['override_percent']);
        $this->assertSame(75, $data['rates']['agreement_percent']);
    }

    public function test_unpriced_reviews_are_never_counted_as_free(): void
    {
        $this->seedReviews([
            ['status' => ContentReviewStatus::Completed, 'cost_micros' => 1200],
            ['status' => ContentReviewStatus::Completed, 'cost_micros' => null],
        ]);

        $data = $this->metrics($this->fullyPermittedAdmin());

        $this->assertSame(1200, $data['cost']['range_cost_micros']);
        $this->assertSame(1, $data['cost']['priced_reviews']);
        $this->assertSame(1, $data['cost']['unpriced_reviews']);
        $this->assertFalse($data['cost']['totals_complete']);
        $this->assertFalse($data['cost']['daily']['totals_complete']);
        $this->assertSame(1, $data['cost']['daily']['unpriced_reviews']);
    }

    public function test_every_admin_receives_the_cost_and_budget_blocks(): void
    {
        $this->seedReviews([['status' => ContentReviewStatus::Completed, 'cost_micros' => 1200]]);

        $data = $this->metrics($this->admin());

        $this->assertArrayHasKey('cost', $data);
        $this->assertArrayHasKey('budget', $data['health']);
        $this->assertSame(1200, $data['cost']['range_cost_micros']);
    }

    public function test_the_image_cache_hit_ratio_is_reported(): void
    {
        $this->seedReviews([
            ['status' => ContentReviewStatus::Completed, 'images_analyzed' => 3, 'image_cache_hits' => 1],
            ['status' => ContentReviewStatus::Completed, 'images_analyzed' => 1, 'image_cache_hits' => 3],
        ]);

        $data = $this->metrics();

        $this->assertSame(4, $data['images']['analyzed']);
        $this->assertSame(4, $data['images']['cache_hits']);
        $this->assertSame(50, $data['images']['cache_hit_percent']);
    }

    public function test_the_queue_block_reports_the_backlog_and_the_last_processed_job(): void
    {
        $this->seedReviews([
            ['status' => ContentReviewStatus::Queued, 'queued_at' => now()->subMinutes(20)],
            ['status' => ContentReviewStatus::Running, 'leased_until' => now()->subMinute()],
        ]);

        app(ContentReviewWorkerHeartbeat::class)->forget();

        $data = $this->metrics();

        $this->assertSame(1, $data['queue']['queued']);
        $this->assertSame(1, $data['queue']['running']);
        $this->assertSame(1, $data['queue']['stale_leases']);
        $this->assertGreaterThan(1_000, $data['queue']['oldest_queued_age_seconds']);
        $this->assertNull($data['queue']['last_job_processed_at']);
        $this->assertNull($data['queue']['seconds_since_last_job']);

        app(ContentReviewWorkerHeartbeat::class)->record();

        $this->assertNotNull($this->metrics()['queue']['last_job_processed_at']);
    }

    public function test_a_range_outside_the_window_is_excluded(): void
    {
        $this->seedReviews([
            ['status' => ContentReviewStatus::Completed, 'created_at' => now()->subDays(20)],
            ['status' => ContentReviewStatus::Completed, 'created_at' => now()->subHour()],
        ]);

        $this->assertSame(1, $this->metrics(null, ['range' => '7d'])['volume']['total']);
        $this->assertSame(2, $this->metrics(null, ['range' => '30d'])['volume']['total']);
        $this->assertSame(1, $this->metrics(null, ['range' => 'today'])['volume']['total']);
    }

    public function test_a_custom_range_is_accepted_and_a_huge_one_is_refused(): void
    {
        config()->set('content_review.metrics.max_range_days', 30);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson(self::PATH.'&date_from='.now()->subDays(5)->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.range.key', 'custom');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson(self::PATH.'&date_from='.now()->subDays(400)->toDateString().'&date_to='.now()->toDateString())
            ->assertStatus(422);
    }

    public function test_the_response_leaks_no_secret(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-leak-canary');
        $this->seedReviews([['status' => ContentReviewStatus::Completed]]);

        $body = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson(self::PATH)
            ->assertOk()
            ->getContent();

        foreach (['sk-ant-leak-canary', 'api_key', 'raw_response', 'chain_of_thought', 'content_hash'] as $canary) {
            $this->assertStringNotContainsString($canary, (string) $body);
        }
    }

    public function test_a_repeated_request_serves_the_cached_aggregate(): void
    {
        config()->set('content_review.metrics.cache_seconds', 300);
        $this->seedReviews([['status' => ContentReviewStatus::Completed]]);

        $first = $this->metrics();
        $this->seedReviews([['status' => ContentReviewStatus::Completed]]);
        $second = $this->metrics();

        $this->assertSame($first['volume']['total'], $second['volume']['total']);
    }

    private function metrics(?object $user = null, array $query = []): array
    {
        $user ??= $this->admin();
        $path = self::PATH.($query === [] ? '' : '&'.http_build_query($query));

        return $this->actingAs($user, 'sanctum')->getJson($path)->assertOk()->json('data');
    }

    private function seedReviews(array $rows): array
    {
        $reviews = app(ContentReviewRepository::class);
        $created = [];
        $subjectId = ContentReview::max('subject_id') ?? 0;

        foreach ($rows as $row) {
            $subjectId++;
            $createdAt = $row['created_at'] ?? null;
            unset($row['created_at']);

            $review = $reviews->create(array_replace([
                'subject_type' => ReviewableSubjectType::Auction->value,
                'subject_id' => $subjectId,
                'content_hash' => hash('sha256', (string) Str::ulid()),
                'trigger' => 'submitted_for_review',
                'mode' => ReviewMode::Shadow->value,
                'status' => ContentReviewStatus::Completed->value,
                'attempt' => 1,
                'max_attempts' => 3,
                'queued_at' => now()->subMinute(),
            ], array_map(
                static fn ($value) => $value instanceof ContentReviewStatus
                    || $value instanceof ContentReviewOutcome
                    || $value instanceof ContentReviewErrorCode
                        ? $value->value
                        : $value,
                $row
            )));

            $attributes = $createdAt === null ? [] : ['created_at' => $createdAt];

            if ($review->status->isTerminal() && $review->completed_at === null) {
                $attributes['completed_at'] = $createdAt ?? $review->created_at;
            }

            if ($attributes !== []) {
                $reviews->update($review, $attributes);
            }

            $created[] = $review;
        }

        return $created;
    }
}
