<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Models\Auction\Auction;
use App\Models\ContentReview\ContentReview;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class BackfillCommandTest extends TestCase
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

    public function test_the_dry_run_is_the_default_and_writes_nothing(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAutomatic);
        $auction = $this->legacyPendingAuction();

        $exitCode = Artisan::call('content-review:backfill', ['--market' => 'jo']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('dry run', $output);
        $this->assertSame(0, ContentReview::count());
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_the_dry_run_reports_the_eligible_count(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->legacyPendingAuction();
        $this->legacyPendingAuction();

        Artisan::call('content-review:backfill', ['--market' => 'jo']);

        $this->assertMatchesRegularExpression('/eligible\s*\|\s*2/', Artisan::output());
    }

    public function test_execute_creates_shadow_reviews_even_when_the_platform_is_automatic(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAutomatic);
        $auction = $this->legacyPendingAuction();

        Artisan::call('content-review:backfill', ['--market' => 'jo', '--execute' => true]);

        $review = ContentReview::where('subject_id', (int) $auction->id)->firstOrFail();

        $this->assertSame(ReviewMode::Shadow, $review->mode);
        $this->assertSame(ReviewTrigger::Backfill, $review->trigger);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_execute_is_refused_while_the_kill_switch_is_off(): void
    {
        config()->set('content_review.enabled', false);
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->legacyPendingAuction();

        $exitCode = Artisan::call('content-review:backfill', ['--market' => 'jo', '--execute' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, ContentReview::count());
    }

    public function test_a_manual_platform_creates_nothing_even_with_execute(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Manual);
        $this->legacyPendingAuction();

        Artisan::call('content-review:backfill', ['--market' => 'jo', '--execute' => true]);

        $this->assertSame(0, ContentReview::count());
        $this->assertMatchesRegularExpression('/skipped\s*\|\s*1/', Artisan::output());
    }

    public function test_content_that_already_has_a_review_is_never_reviewed_again(): void
    {
        $reviewed = $this->reviewedAuction(ReviewMode::Shadow);
        $this->legacyPendingAuction();

        Artisan::call('content-review:backfill', ['--market' => 'jo', '--execute' => true]);

        $this->assertSame(1, ContentReview::where('subject_id', (int) $reviewed->id)->count());
        $this->assertMatchesRegularExpression('/already reviewed\s*\|\s*1/', Artisan::output());
    }

    public function test_the_limit_bounds_how_much_is_enqueued(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);

        for ($index = 0; $index < 4; $index++) {
            $this->legacyPendingAuction();
        }

        Artisan::call('content-review:backfill', ['--market' => 'jo', '--execute' => true, '--limit' => 2]);

        $this->assertSame(2, ContentReview::count());
    }

    public function test_the_command_is_not_scheduled(): void
    {
        $this->assertStringNotContainsString('content-review:backfill', file_get_contents(base_path('routes/console.php')));
    }

    private function legacyPendingAuction(): Auction
    {
        $auction = $this->draftAuction();
        $this->attachMedia($auction);
        $auction->forceFill(['status' => AuctionStatus::PendingReview->value])->save();

        return $auction->refresh();
    }
}
