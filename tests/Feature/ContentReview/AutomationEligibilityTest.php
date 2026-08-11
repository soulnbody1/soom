<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\DTO\ContentReview\AutomationContext;
use App\Models\Auction\Auction;
use App\Models\ContentReview\ContentReview;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\AutomationEligibilityResolver;
use App\Services\ContentReview\Support\ContentHasher;
use App\Services\ContentReview\Support\ContentReviewBudgetGuard;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class AutomationEligibilityTest extends TestCase
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

    public function test_a_fully_qualified_subject_reports_no_reason(): void
    {
        $review = $this->analysedAuction();

        $this->assertTrue($this->context($review)->isAutomationEligible);
        $this->assertSame([], $this->context($review)->reasons);
        $this->assertSame([], $this->context($review)->approvalBlockers);
    }

    public function test_the_master_kill_switch_alone_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        config()->set('content_review.enabled', false);

        $this->assertSame(['content_review_disabled'], $this->context($review)->reasons);
    }

    public function test_a_non_automatic_current_mode_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        $this->publishSettings(ReviewMode::AiAssisted);

        $this->assertContains('mode_not_automatic', $this->context($review)->reasons);
    }

    public function test_an_attempt_frozen_below_automatic_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        $review->forceFill(['mode' => ReviewMode::AiAssisted->value])->save();

        $this->assertContains('frozen_mode_not_automatic', $this->context($review->refresh())->reasons);
    }

    public function test_a_category_outside_the_allowlist_blocks_automation(): void
    {
        $review = $this->analysedAuction(automationOverrides: ['allowed_category_ids' => []]);

        $this->assertContains('category_not_allowed', $this->context($review)->reasons);
    }

    public function test_a_value_above_the_cap_blocks_automation(): void
    {
        $review = $this->analysedAuction(automationOverrides: ['max_starting_amount_minor' => 1]);

        $this->assertContains('value_above_automation_cap', $this->context($review)->reasons);
    }

    public function test_a_missing_required_image_blocks_automation(): void
    {
        $review = $this->analysedAuction(images: 0);

        $this->assertContains('required_images_missing', $this->context($review)->reasons);
    }

    public function test_a_missing_terms_version_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        Auction::whereKey((int) $review->subject_id)->update(['terms_version_id' => null]);
        $this->rehashContent($review);

        $this->assertContains('terms_version_missing', $this->context($review)->reasons);
    }

    public function test_a_missing_configuration_version_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        Auction::whereKey((int) $review->subject_id)->update(['configuration_version_id' => null]);

        $this->assertContains('configuration_version_missing', $this->context($review)->reasons);
    }

    public function test_a_superseded_attempt_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        $review->forceFill(['current_marker' => null, 'status' => ContentReviewStatus::Superseded->value])->save();

        $this->assertContains('review_not_current', $this->context($review->refresh())->reasons);
    }

    public function test_an_incomplete_attempt_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        $review->forceFill(['status' => ContentReviewStatus::Failed->value])->save();

        $this->assertContains('review_not_completed', $this->context($review->refresh())->reasons);
    }

    public function test_a_missing_recommendation_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        $review->forceFill(['recommendation' => null])->save();

        $this->assertContains('recommendation_missing', $this->context($review->refresh())->reasons);
    }

    public function test_a_hard_deterministic_failure_blocks_automation(): void
    {
        $review = $this->analysedAuction(policyOverrides: ['deterministic_rules' => [
            'min_description_length' => 5000,
            'require_at_least_one_image' => false,
            'forbid_contact_patterns' => true,
            'reserve_must_not_exceed_starting_multiplier' => 100,
        ]]);

        $this->assertContains('deterministic_hard_failure', $this->context($review)->reasons);
    }

    public function test_changed_content_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        Auction::whereKey((int) $review->subject_id)->update(['title' => 'A completely different headline for the listing']);

        $this->assertContains('review_stale', $this->context($review)->reasons);
    }

    public function test_a_subject_that_left_the_review_queue_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        Auction::whereKey((int) $review->subject_id)->update(['status' => 'rejected']);

        $this->assertContains('subject_not_reviewable', $this->context($review)->reasons);
    }

    public function test_an_image_that_could_not_be_prepared_blocks_automation(): void
    {
        $review = $this->analysedAuction(mediaOverrides: [['unreadable' => true]]);

        $this->assertContains('image_preparation_failed', $this->context($review)->reasons);
    }

    public function test_an_image_the_model_never_scored_blocks_automation(): void
    {
        $review = $this->analysedAuction(images: 2, imageChecks: 1);

        $this->assertContains('image_not_screened', $this->context($review)->reasons);
    }

    public function test_a_flagged_image_blocks_only_the_automatic_approval(): void
    {
        $review = $this->analysedAuction(imageVerdict: 'flagged');
        $context = $this->context($review);

        $this->assertTrue($context->isAutomationEligible);
        $this->assertSame(['image_flagged'], $context->approvalBlockers);
        $this->assertTrue($context->blocksAutomaticApproval());
    }

    public function test_an_open_circuit_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        $breaker = app(ContentReviewCircuitBreaker::class);

        for ($failure = 0; $failure < 5; $failure++) {
            $breaker->recordFailure(['circuit_breaker' => ['failure_threshold' => 1, 'window_seconds' => 300, 'open_seconds' => 600]]);
        }

        $this->assertTrue($breaker->isOpen());
        $this->assertContains('provider_circuit_open', $this->context($review)->reasons);
    }

    public function test_an_exhausted_budget_blocks_automation(): void
    {
        $review = $this->analysedAuction();

        $this->publishSettings(ReviewMode::AiAutomatic, [
            'daily_budget_micros' => 0,
            'monthly_budget_micros' => 0,
            'automation' => [
                'allowed_category_ids' => [(int) Auction::whereKey((int) $review->subject_id)->value('category_id')],
                'max_starting_amount_minor' => 1_000_000,
                'require_images' => true,
            ],
        ]);

        $this->assertNotNull(app(ContentReviewBudgetGuard::class)->exhaustedPeriod(
            ['daily_budget_micros' => 0, 'monthly_budget_micros' => 0],
            'claude-sonnet-5',
            2000
        ));
        $this->assertContains('budget_exhausted', $this->context($review)->reasons);
    }

    public function test_an_ineligible_subject_never_reaches_an_automatic_outcome(): void
    {
        $review = $this->analysedAuction(
            automationOverrides: ['allowed_category_ids' => []],
            requiresHumanReview: false,
        );

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame('subject_not_automation_eligible', $review->reason_code);
    }

    public function test_an_eligible_subject_reaches_an_automatic_approval(): void
    {
        $review = $this->analysedAuction(requiresHumanReview: false);

        $this->assertSame(ContentReviewOutcome::AutoApproved, $review->outcome);
        $this->assertSame('high_confidence_clean', $review->reason_code);
    }

    private function context(ContentReview $review): AutomationContext
    {
        return app(AutomationEligibilityResolver::class)->resolve($review->refresh());
    }

    private function rehashContent(ContentReview $review): void
    {
        $content = app(ReviewSubjectRegistry::class)
            ->for($review->subject_type)
            ->buildContent((int) $review->subject_id);

        $review->forceFill(['content_hash' => app(ContentHasher::class)->hashContent($content)])->save();
    }

    private function analysedAuction(
        array $policyOverrides = [],
        array $automationOverrides = [],
        int $images = 1,
        array $mediaOverrides = [],
        ?int $imageChecks = null,
        string $imageVerdict = 'clean',
        bool $requiresHumanReview = true,
    ): ContentReview {
        $auction = $this->reviewedAuction(
            ReviewMode::AiAutomatic,
            $policyOverrides,
            $automationOverrides,
            $images,
            $mediaOverrides,
        );

        $this->fakeProvider()->respondWith(array_replace($this->cleanResultPayload(), [
            'requires_human_review' => $requiresHumanReview,
            'image_checks' => $this->imageCheckPayload($imageChecks ?? $images, $imageVerdict),
        ]));

        return $this->processActiveReview($auction);
    }
}
