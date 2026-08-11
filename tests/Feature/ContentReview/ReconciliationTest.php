<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Actions\ReconcileContentReviewsAction;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class ReconciliationTest extends TestCase
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

    public function test_a_dry_run_reports_without_repairing(): void
    {
        $review = $this->expiredLease();

        $report = $this->reconcile(false);

        $this->assertSame(1, $report['repairable']['expired_leases']);
        $this->assertSame(0, $report['repaired']['expired_leases']);
        $this->assertSame(ContentReviewStatus::Running, $review->refresh()->status);
    }

    public function test_an_expired_lease_goes_back_to_queued(): void
    {
        $review = $this->expiredLease();

        $report = $this->reconcile(true);

        $this->assertSame(1, $report['repaired']['expired_leases']);
        $this->assertSame(ContentReviewStatus::Queued, $review->refresh()->status);
        $this->assertNull($review->refresh()->lease_owner);
        $this->assertNull($review->refresh()->leased_until);
    }

    public function test_a_live_lease_is_never_touched(): void
    {
        $review = $this->activeReview($this->reviewedAuction(ReviewMode::Shadow));
        app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Running->value,
            'lease_owner' => 'worker-1',
            'leased_until' => now()->addMinutes(5),
        ]);

        $report = $this->reconcile(true);

        $this->assertSame(0, $report['repairable']['expired_leases']);
        $this->assertSame(ContentReviewStatus::Running, $review->refresh()->status);
    }

    public function test_a_cancelled_row_stops_claiming_to_be_the_active_one(): void
    {
        $review = $this->activeReview($this->reviewedAuction(ReviewMode::Shadow));
        app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Cancelled->value,
            'current_marker' => 1,
        ]);

        $report = $this->reconcile(true);

        $this->assertSame(1, $report['repaired']['terminal_rows_marked_active']);
        $this->assertNull($review->refresh()->current_marker);
    }

    public function test_the_repairs_are_idempotent(): void
    {
        $this->expiredLease();

        $first = $this->reconcile(true);
        $second = $this->reconcile(true);

        $this->assertSame(1, $first['repaired']['expired_leases']);
        $this->assertSame(0, $second['repairable']['expired_leases']);
        $this->assertSame(0, $second['repaired']['expired_leases']);
    }

    public function test_cases_that_need_a_human_are_counted_and_not_changed(): void
    {
        $review = $this->activeReview($this->reviewedAuction(ReviewMode::Shadow));
        app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Completed->value,
            'outcome' => null,
            'decided_at' => null,
            'completed_at' => now()->subHours(4),
        ]);

        $report = $this->reconcile(true);

        $this->assertSame(1, $report['needs_admin']['completed_without_application_state']);
        $this->assertSame(ContentReviewStatus::Completed, $review->refresh()->status);
        $this->assertNull($review->refresh()->outcome);
    }

    public function test_a_healthy_platform_needs_no_repair(): void
    {
        $this->reviewedAuction(ReviewMode::Shadow);

        $report = $this->reconcile(true);

        $this->assertSame(0, $report['repairable']['expired_leases']);
        $this->assertSame(0, $report['repairable']['terminal_rows_marked_active']);
        $this->assertSame(0, $report['needs_admin']['subjects_with_multiple_active_reviews']);
        $this->assertSame(0, $report['needs_admin']['decisions_without_review']);
    }

    public function test_the_command_defaults_to_a_dry_run_and_deletes_nothing(): void
    {
        $this->expiredLease();
        $before = ContentReview::count();

        $exitCode = Artisan::call('content-review:reconcile');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('dry run', Artisan::output());
        $this->assertSame($before, ContentReview::count());
    }

    public function test_the_command_applies_the_repairs_with_the_flag(): void
    {
        $review = $this->expiredLease();

        Artisan::call('content-review:reconcile', ['--apply' => true]);

        $this->assertSame(ContentReviewStatus::Queued, $review->refresh()->status);
    }

    public function test_the_command_is_not_scheduled(): void
    {
        $this->assertStringNotContainsString('content-review:reconcile', file_get_contents(base_path('routes/console.php')));
    }

    private function reconcile(bool $apply): array
    {
        return app(ReconcileContentReviewsAction::class)->execute($apply, 100);
    }

    private function expiredLease(): ContentReview
    {
        $review = $this->activeReview($this->reviewedAuction(ReviewMode::Shadow));

        return app(ContentReviewRepository::class)->update($review, [
            'status' => ContentReviewStatus::Running->value,
            'lease_owner' => 'worker-1',
            'leased_until' => now()->subMinutes(30),
        ]);
    }
}
