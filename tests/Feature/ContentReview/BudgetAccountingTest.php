<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewMetricsRepository;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use App\Services\ContentReview\Support\ContentReviewBudgetGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class BudgetAccountingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_spend_is_billed_to_the_period_the_call_was_made_in(): void
    {
        $this->review(createdAt: Carbon::now()->subDay()->setTime(23, 55), completedAt: Carbon::now()->setTime(0, 5), cost: 700);

        $this->assertSame(700, $this->guard()->spentMicros('daily'));
    }

    public function test_spend_from_an_earlier_period_does_not_leak_into_this_one(): void
    {
        $this->review(
            createdAt: Carbon::now()->subDays(3),
            completedAt: Carbon::now()->subDays(3)->setTime(12, 0),
            cost: 900,
        );

        $this->assertSame(0, $this->guard()->spentMicros('daily'));
    }

    public function test_an_unfinished_call_is_not_counted_as_spend(): void
    {
        $this->review(createdAt: Carbon::now(), completedAt: null, cost: 500, status: ContentReviewStatus::Queued);

        $this->assertSame(0, $this->guard()->spentMicros('daily'));
    }

    public function test_the_monthly_window_follows_the_same_rule(): void
    {
        $this->review(
            createdAt: Carbon::now()->startOfMonth()->subDay(),
            completedAt: Carbon::now()->startOfMonth()->addHour(),
            cost: 1200,
        );

        $this->assertSame(1200, $this->guard()->spentMicros('monthly'));
    }

    public function test_unpriced_calls_are_counted_over_the_same_window_as_the_spend(): void
    {
        $this->review(
            createdAt: Carbon::now()->subDay()->setTime(23, 50),
            completedAt: Carbon::now()->setTime(0, 10),
            cost: null,
        );

        $snapshot = $this->guard()->periodSnapshot(
            new ReviewSettings(['daily_budget_micros' => 1_000_000]),
            'daily',
            app(ContentReviewMetricsRepository::class)->unpricedSince(Carbon::now()->startOfDay()),
        );

        $this->assertSame(1, $snapshot['unpriced_reviews']);
        $this->assertFalse($snapshot['totals_complete']);
    }

    public function test_the_stub_provider_is_refused_in_production_unless_asked_for(): void
    {
        $factory = app(ContentReviewProviderFactory::class);

        $this->assertTrue($factory->isConfigured('fake'));

        app()->detectEnvironment(static fn (): string => 'production');

        $this->assertFalse(
            $factory->isConfigured('fake'),
            'A production deployment that forgot to configure a provider must not silently file invented results.'
        );

        config()->set('content_review.allow_fake_provider', true);

        $this->assertTrue($factory->isConfigured('fake'));
    }

    public function test_a_real_provider_is_unaffected_by_the_production_guard(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->assertSame('anthropic', app(ContentReviewProviderFactory::class)->make('anthropic')->name());
    }

    private function guard(): ContentReviewBudgetGuard
    {
        return app(ContentReviewBudgetGuard::class);
    }

    private function review(
        Carbon $createdAt,
        ?Carbon $completedAt,
        ?int $cost,
        ContentReviewStatus $status = ContentReviewStatus::Completed,
    ): ContentReview {
        $review = ContentReview::create([
            'subject_type' => ReviewableSubjectType::Auction->value,
            'subject_id' => 1,
            'content_hash' => str_repeat('a', 64),
            'mode' => 'ai_automatic',
            'trigger' => ReviewTrigger::AdminManual->value,
            'status' => $status->value,
            'requires_human_review' => true,
            'attempt' => 1,
            'max_attempts' => 3,
            'cost_micros' => $cost,
            'queued_at' => $createdAt,
        ]);

        $review->forceFill(['created_at' => $createdAt, 'completed_at' => $completedAt])->save();

        return $review;
    }
}
