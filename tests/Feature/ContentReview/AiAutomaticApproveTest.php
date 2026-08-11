<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionStatusHistory;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;
use App\Models\User;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class AiAutomaticApproveTest extends TestCase
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

    public function test_an_automatic_approval_is_attributed_to_the_ai_and_creates_no_user(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAutomatic);
        $this->fakeProvider()->respondWith(array_replace($this->cleanResultPayload(), [
            'image_checks' => $this->imageCheckPayload(1),
        ]));

        $usersBefore = User::count();
        $review = $this->processActiveReview($auction);
        $auction->refresh();

        $this->assertSame(ContentReviewOutcome::AutoApproved, $review->outcome);
        $this->assertSame('high_confidence_clean', $review->reason_code);
        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->status);
        $this->assertSame($usersBefore, User::count());

        $decision = ContentReviewDecision::where('review_id', $review->id)->firstOrFail();

        $this->assertSame(DecisionActorType::Ai, $decision->decided_by_type);
        $this->assertNull($decision->decided_by_id);
        $this->assertSame(ContentReviewDecisionType::Approved, $decision->decision);
        $this->assertSame(DecisionRelation::None, $decision->relation_to_recommendation);

        $transition = AuctionStatusHistory::where('auction_id', $auction->id)
            ->where('to_status', AuctionStatus::AwaitingSellerDeposit->value)
            ->firstOrFail();

        $this->assertSame('ai', $transition->actor_type);
        $this->assertNull($transition->changed_by);
    }

    public function test_an_automatic_approval_creates_exactly_one_snapshot_and_one_deposit(): void
    {
        $review = $this->analysed();
        $auction = Auction::whereKey((int) $review->subject_id)->firstOrFail();

        $this->assertSame(1, AuctionConfigurationSnapshot::where('auction_id', $auction->id)->count());
        $this->assertNull(AuctionConfigurationSnapshot::where('auction_id', $auction->id)->firstOrFail()->created_by);
        $this->assertSame(1, AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->count());
        $this->assertSame(1, ContentReviewDecision::where('review_id', $review->id)->count());
    }

    public function test_one_point_below_the_approval_threshold_goes_to_a_human(): void
    {
        $review = $this->analysed(['confidence' => 84]);

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame('grey_zone', $review->reason_code);
        $this->assertSame(AuctionStatus::PendingReview, $this->auctionStatus($review));
    }

    public function test_exactly_the_approval_threshold_is_approved(): void
    {
        $review = $this->analysed(['confidence' => 85]);

        $this->assertSame(ContentReviewOutcome::AutoApproved, $review->outcome);
    }

    public function test_one_point_above_the_approval_threshold_is_approved(): void
    {
        $review = $this->analysed(['confidence' => 86]);

        $this->assertSame(ContentReviewOutcome::AutoApproved, $review->outcome);
    }

    public function test_the_maximum_allowed_risk_level_is_approved(): void
    {
        $review = $this->analysed(['risk_level' => 'medium'], ['max_risk_level_for_auto_approve' => 'medium']);

        $this->assertSame(ContentReviewOutcome::AutoApproved, $review->outcome);
    }

    public function test_one_risk_level_above_the_ceiling_goes_to_a_human(): void
    {
        $review = $this->analysed(['risk_level' => 'high'], ['max_risk_level_for_auto_approve' => 'medium']);

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $this->auctionStatus($review));
    }

    public function test_any_violation_prevents_an_automatic_approval(): void
    {
        $review = $this->analysed(['violations' => [[
            'code' => 'incomplete_information',
            'severity' => 'low',
            'field' => 'description',
            'evidence' => 'missing detail',
        ]]], ['violation_codes' => ['incomplete_information']]);

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $this->auctionStatus($review));
    }

    public function test_a_prohibited_category_prevents_an_automatic_approval(): void
    {
        $review = $this->analysed(['categories' => ['weapons']]);

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
    }

    public function test_a_flagged_image_prevents_an_automatic_approval(): void
    {
        $review = $this->analysed(imageVerdict: 'flagged');

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame('image_review_blocks_approval', $review->reason_code);
        $this->assertSame(AuctionStatus::PendingReview, $this->auctionStatus($review));
    }

    public function test_an_unscreened_image_prevents_an_automatic_approval(): void
    {
        $review = $this->analysed(images: 2, imageChecks: 1);

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame('subject_not_automation_eligible', $review->reason_code);
    }

    public function test_a_failed_image_never_produces_an_automatic_approval(): void
    {
        $review = $this->analysed(mediaOverrides: [['unreadable' => true]]);

        $this->assertSame(ContentReviewOutcome::EscalatedToHuman, $review->outcome);
        $this->assertSame(AuctionStatus::PendingReview, $this->auctionStatus($review));
    }

    public function test_an_automatic_decision_records_its_full_reason_trail(): void
    {
        $review = $this->analysed();

        $this->assertSame('high_confidence_clean', $review->reason_code);
        $this->assertNotNull($review->recommendation);
        $this->assertSame(96, (int) $review->confidence);
        $this->assertNotNull($review->risk_level);
        $this->assertNotNull($review->policy_version);
        $this->assertNotNull($review->settings_version);
        $this->assertSame('fake', $review->provider);
        $this->assertSame('claude-sonnet-5', $review->model);
        $this->assertSame(1, $review->image_review['counts']['analyzed']);
        $this->assertSame(0, $review->image_review['counts']['cache_hits']);
        $this->assertNotSame([], $review->deterministic_findings);
    }

    public function test_a_second_pass_over_a_decided_review_changes_nothing(): void
    {
        $review = $this->analysed();
        $statusAfter = $this->auctionStatus($review);

        $this->processActiveReview(Auction::whereKey((int) $review->subject_id)->firstOrFail());

        $this->assertSame($statusAfter, $this->auctionStatus($review));
        $this->assertSame(1, ContentReviewDecision::where('review_id', $review->id)->count());
        $this->assertSame(1, AuctionConfigurationSnapshot::where('auction_id', (int) $review->subject_id)->count());
        $this->assertSame(
            1,
            AuctionDeposit::where('auction_id', (int) $review->subject_id)->where('type', 'seller')->count()
        );
    }

    public function test_a_content_change_after_the_analysis_prevents_the_decision(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAutomatic);
        $this->fakeProvider()->respondWith(array_replace($this->cleanResultPayload(), [
            'image_checks' => $this->imageCheckPayload(1),
        ]));

        $review = $this->activeReview($auction);

        Auction::whereKey((int) $auction->id)->update(['title' => 'A brand new title chosen after the analysis']);

        app(\App\Services\ContentReview\Actions\ProcessContentReviewAction::class)->execute((string) $review->public_id);
        $review->refresh();

        $this->assertSame(ContentReviewOutcome::NoDecision, $review->outcome);
        $this->assertNull($review->current_marker);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
        $this->assertSame(0, ContentReviewDecision::count());
    }

    private function auctionStatus(ContentReview $review): AuctionStatus
    {
        return Auction::whereKey((int) $review->subject_id)->firstOrFail()->status;
    }

    private function analysed(
        array $payloadOverrides = [],
        array $policyOverrides = [],
        int $images = 1,
        ?int $imageChecks = null,
        array $mediaOverrides = [],
        string $imageVerdict = 'clean',
    ): ContentReview {
        $auction = $this->reviewedAuction(
            ReviewMode::AiAutomatic,
            $policyOverrides,
            [],
            $images,
            $mediaOverrides,
        );

        $this->fakeProvider()->respondWith(array_replace($this->cleanResultPayload(), $payloadOverrides, [
            'image_checks' => $this->imageCheckPayload($imageChecks ?? $images, $imageVerdict),
        ]));

        return $this->processActiveReview($auction);
    }
}
