<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionStatusHistory;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Models\User;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class AiAutomaticRejectTest extends TestCase
{
    use BuildsContentReviewFixtures;

    private const CRITICAL_VIOLATION = [
        'code' => 'prohibited_item',
        'severity' => 'critical',
        'field' => 'description',
        'evidence' => 'prohibited item',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config()->set('content_review.enabled', true);
        Http::preventStrayRequests();
        Queue::fake();
        app(FakeContentReviewProvider::class)->reset();
    }

    public function test_the_shipped_policy_default_rejects_nothing_automatically(): void
    {
        $review = $this->analysed();

        $this->assertSame([], app(ContentReviewPolicy::class)::where('is_active', true)
            ->firstOrFail()
            ->policy['auto_reject_categories']);
        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $this->auctionStatus($review));
        $this->assertSame(0, ContentReviewDecision::where('decision', ContentReviewDecisionType::Rejected->value)->count());
    }

    public function test_an_allowlisted_critical_violation_is_rejected_automatically(): void
    {
        $usersBefore = User::count();
        $review = $this->analysed(policyOverrides: ['auto_reject_categories' => ['prohibited_item']]);
        $auction = Auction::whereKey((int) $review->subject_id)->firstOrFail();

        $this->assertSame(ContentReviewOutcome::AutoRejected, $review->outcome);
        $this->assertSame('high_confidence_critical_violation', $review->reason_code);
        $this->assertSame(AuctionStatus::Rejected, $auction->status);
        $this->assertSame($usersBefore + 1, User::count(), 'Only the fixture seller may be created.');

        $decision = ContentReviewDecision::where('review_id', $review->id)->firstOrFail();

        $this->assertSame(DecisionActorType::Ai, $decision->decided_by_type);
        $this->assertNull($decision->decided_by_id);
        $this->assertSame(ContentReviewDecisionType::Rejected, $decision->decision);

        $transition = AuctionStatusHistory::where('auction_id', $auction->id)
            ->where('to_status', AuctionStatus::Rejected->value)
            ->firstOrFail();

        $this->assertSame('ai', $transition->actor_type);
        $this->assertNull($transition->changed_by);
        $this->assertNotSame('', trim((string) $transition->reason));
    }

    public function test_a_rejection_creates_no_snapshot_and_no_deposit(): void
    {
        $review = $this->analysed(policyOverrides: ['auto_reject_categories' => ['prohibited_item']]);

        $this->assertSame(0, AuctionConfigurationSnapshot::where('auction_id', (int) $review->subject_id)->count());
        $this->assertSame(0, AuctionDeposit::where('auction_id', (int) $review->subject_id)->count());
    }

    public function test_a_critical_violation_outside_the_allowlist_goes_to_a_human(): void
    {
        $review = $this->analysed(policyOverrides: ['auto_reject_categories' => ['misleading_description']]);

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $this->auctionStatus($review));
    }

    public function test_a_non_critical_violation_goes_to_a_human(): void
    {
        $review = $this->analysed(
            payloadOverrides: ['violations' => [array_replace(self::CRITICAL_VIOLATION, ['severity' => 'high'])]],
            policyOverrides: ['auto_reject_categories' => ['prohibited_item']],
        );

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $this->auctionStatus($review));
    }

    public function test_one_point_below_the_rejection_threshold_goes_to_a_human(): void
    {
        $review = $this->analysed(
            payloadOverrides: ['confidence' => 89],
            policyOverrides: ['auto_reject_categories' => ['prohibited_item']],
        );

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
    }

    public function test_exactly_the_rejection_threshold_is_rejected(): void
    {
        $review = $this->analysed(
            payloadOverrides: ['confidence' => 90],
            policyOverrides: ['auto_reject_categories' => ['prohibited_item']],
        );

        $this->assertSame(ContentReviewOutcome::AutoRejected, $review->outcome);
    }

    public function test_a_reject_recommendation_without_any_violation_goes_to_a_human(): void
    {
        $review = $this->analysed(
            payloadOverrides: ['violations' => []],
            policyOverrides: ['auto_reject_categories' => ['prohibited_item']],
        );

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
    }

    public function test_a_human_review_only_category_always_goes_to_a_human(): void
    {
        $review = $this->analysed(
            payloadOverrides: ['categories' => ['counterfeit']],
            policyOverrides: ['auto_reject_categories' => ['prohibited_item']],
        );

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame('policy_requires_human', $review->reason_code);
    }

    public function test_a_flagged_image_does_not_block_an_automatic_rejection(): void
    {
        $review = $this->analysed(
            policyOverrides: ['auto_reject_categories' => ['prohibited_item']],
            imageVerdict: 'flagged',
        );

        $this->assertSame(ContentReviewOutcome::AutoRejected, $review->outcome);
        $this->assertSame(AuctionStatus::Rejected, $this->auctionStatus($review));
    }

    public function test_an_unscreened_image_blocks_an_automatic_rejection(): void
    {
        $review = $this->analysed(
            policyOverrides: ['auto_reject_categories' => ['prohibited_item']],
            images: 2,
            imageChecks: 1,
        );

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame('subject_not_automation_eligible', $review->reason_code);
        $this->assertSame(AuctionStatus::PendingReview, $this->auctionStatus($review));
    }

    public function test_an_ineligible_subject_is_never_rejected_automatically(): void
    {
        $auction = $this->reviewedAuction(
            ReviewMode::AiAutomatic,
            ['auto_reject_categories' => ['prohibited_item']],
            ['allowed_category_ids' => []],
        );

        $this->fakeProvider()->respondWith($this->rejectPayload());
        $review = $this->processActiveReview($auction);

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame('subject_not_automation_eligible', $review->reason_code);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_assisted_mode_never_rejects_automatically(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAssisted, ['auto_reject_categories' => ['prohibited_item']]);

        $this->fakeProvider()->respondWith($this->rejectPayload());
        $review = $this->processActiveReview($auction);

        $this->assertSame(ContentReviewOutcome::AdvisoryOnly, $review->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    private function auctionStatus(ContentReview $review): AuctionStatus
    {
        return Auction::whereKey((int) $review->subject_id)->firstOrFail()->status;
    }

    private function rejectPayload(array $overrides = [], int $images = 1, string $imageVerdict = 'clean'): array
    {
        return array_replace([
            'recommendation' => 'reject',
            'confidence' => 97,
            'risk_level' => 'critical',
            'requires_human_review' => false,
            'summary_ar' => 'الإعلان يعرض صنفًا محظورًا.',
            'summary_en' => 'The listing offers a prohibited item.',
            'categories' => [],
            'violations' => [self::CRITICAL_VIOLATION],
            'findings' => [],
            'policy_checks' => [],
            'missing_information' => [],
        ], $overrides, ['image_checks' => $this->imageCheckPayload($images, $imageVerdict)]);
    }

    private function analysed(
        array $payloadOverrides = [],
        array $policyOverrides = [],
        int $images = 1,
        ?int $imageChecks = null,
        string $imageVerdict = 'clean',
    ): ContentReview {
        $auction = $this->reviewedAuction(ReviewMode::AiAutomatic, $policyOverrides, [], $images);

        $this->fakeProvider()->respondWith(
            $this->rejectPayload($payloadOverrides, $imageChecks ?? $images, $imageVerdict)
        );

        return $this->processActiveReview($auction);
    }
}
