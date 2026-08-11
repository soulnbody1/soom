<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionStatusHistory;
use App\Models\Auction\OutboxMessage;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;
use App\Models\User;
use App\Services\ContentReview\Actions\ProcessContentReviewAction;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class AiOverrideTest extends TestCase
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

    public function test_an_override_from_approve_to_reject_is_recorded(): void
    {
        [$review, $auction] = $this->assistedReview();
        $admin = $this->overrider();

        $this->decide($admin, $review, 'reject', 'The images do not match the description.')->assertOk();

        $this->assertSame(AuctionStatus::Rejected, $auction->refresh()->status);

        $decision = $this->humanDecision($review);
        $this->assertSame(ContentReviewDecisionType::Rejected, $decision->decision);
        $this->assertSame(DecisionRelation::Overridden, $decision->relation_to_recommendation);
        $this->assertSame(DecisionActorType::Admin, $decision->decided_by_type);
        $this->assertSame((int) $admin->id, (int) $decision->decided_by_id);
        $this->assertSame('approve', $decision->ai_recommendation?->value);
        $this->assertSame(96, (int) $decision->ai_confidence);
        $this->assertSame('The images do not match the description.', $decision->reason);

        $history = AuctionStatusHistory::where('auction_id', $auction->id)
            ->where('to_status', AuctionStatus::Rejected->value)
            ->firstOrFail();
        $this->assertSame('admin', $history->actor_type);
        $this->assertSame((int) $admin->id, (int) $history->changed_by);

        $this->assertSame(1, OutboxMessage::where('event_type', 'content_review.overridden')->count());
        $this->assertSame(0, OutboxMessage::where('event_type', 'content_review.confirmed')->count());
    }

    public function test_an_override_from_reject_to_approve_is_recorded(): void
    {
        [$review, $auction] = $this->assistedReview($this->rejectResultPayload());

        $this->decide($this->overrider(), $review, 'approve', 'Reviewed the evidence manually.')->assertOk();

        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);

        $decision = $this->humanDecision($review);
        $this->assertSame(DecisionRelation::Overridden, $decision->relation_to_recommendation);
        $this->assertSame('reject', $decision->ai_recommendation?->value);
    }

    public function test_any_admin_may_override_when_a_reason_is_given(): void
    {
        [$review, $auction] = $this->assistedReview();

        $this->decide($this->admin(), $review, 'reject', 'The images do not match.')->assertOk();

        $this->assertSame(AuctionStatus::Rejected, $auction->refresh()->status);
        $this->assertSame(1, $this->humanDecisionCount());
        $this->assertSame(DecisionRelation::Overridden, $this->humanDecision($review)->relation_to_recommendation);
    }

    public function test_an_override_without_a_reason_is_refused(): void
    {
        [$review, $auction] = $this->assistedReview();

        $this->decide($this->overrider(), $review, 'reject')
            ->assertStatus(422)
            ->assertJsonPath('code', 'override_reason_required');

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
        $this->assertSame(0, $this->humanDecisionCount());
    }

    public function test_a_whitespace_only_override_reason_is_refused(): void
    {
        [$review, $auction] = $this->assistedReview();

        $this->decide($this->overrider(), $review, 'reject', '   ')
            ->assertStatus(422)
            ->assertJsonPath('code', 'override_reason_required');

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_an_override_reason_longer_than_the_project_limit_is_refused(): void
    {
        [$review] = $this->assistedReview();

        $this->decide($this->overrider(), $review, 'reject', str_repeat('x', 1001))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /**
     * The older route enforces the reason one layer earlier, in validation, so an
     * override still cannot land without one. Nothing is recorded either way.
     */
    public function test_the_manual_endpoint_cannot_bypass_the_mandatory_override_reason(): void
    {
        [$review, $auction] = $this->assistedReview();

        $this->actingAs($this->decider(), 'sanctum')
            ->postJson("/api/admin/auctions/{$auction->public_id}/review", [
                'action' => 'reject',
                'reason' => '   ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
        $this->assertSame(0, $this->humanDecisionCount());
        $this->assertSame(
            0,
            AuctionStatusHistory::where('auction_id', $auction->id)
                ->where('to_status', AuctionStatus::Rejected->value)
                ->count()
        );
    }

    public function test_the_manual_endpoint_records_an_override_with_a_reason(): void
    {
        [$review, $auction] = $this->assistedReview();
        $admin = $this->overrider();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/auctions/{$auction->public_id}/review", [
                'action' => 'reject',
                'reason' => 'Contradicts the recommendation on purpose.',
            ])
            ->assertOk();

        $this->assertSame(AuctionStatus::Rejected, $auction->refresh()->status);

        $decision = $this->humanDecision($review);
        $this->assertSame(DecisionRelation::Overridden, $decision->relation_to_recommendation);
        $this->assertSame((int) $admin->id, (int) $decision->decided_by_id);
        $this->assertSame(1, OutboxMessage::where('event_type', 'content_review.overridden')->count());
    }

    public function test_the_manual_endpoint_records_a_confirmation_when_it_agrees(): void
    {
        [$review, $auction] = $this->assistedReview();
        $admin = $this->decider();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/auctions/{$auction->public_id}/review", [
                'action' => 'approve',
                'reason' => 'Agrees with the recommendation.',
            ])
            ->assertOk();

        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);

        $decision = $this->humanDecision($review);
        $this->assertSame(DecisionRelation::Confirmed, $decision->relation_to_recommendation);
        $this->assertSame((int) $admin->id, (int) $decision->decided_by_id);
        $this->assertSame(1, OutboxMessage::where('event_type', 'content_review.confirmed')->count());
    }

    public function test_the_manual_endpoint_is_untouched_when_no_review_exists(): void
    {
        $this->publishSettings(ReviewMode::Manual);
        $auction = $this->submitForReview();

        $this->assertSame(0, ContentReview::count());

        $this->actingAs($this->decider(), 'sanctum')
            ->postJson("/api/admin/auctions/{$auction->public_id}/review", [
                'action' => 'approve',
                'reason' => 'Plain manual approval.',
            ])
            ->assertOk();

        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);
        $this->assertSame(0, ContentReviewDecision::count());
    }

    public function test_a_shadow_recommendation_records_the_relation_without_gating_the_manual_route(): void
    {
        [$review, $auction] = $this->assistedReview(null, ReviewMode::Shadow);

        $this->actingAs($this->decider(), 'sanctum')
            ->postJson("/api/admin/auctions/{$auction->public_id}/review", [
                'action' => 'reject',
                'reason' => 'Shadow mode keeps the employee in charge.',
            ])
            ->assertOk();

        $this->assertSame(AuctionStatus::Rejected, $auction->refresh()->status);
        $this->assertSame(DecisionRelation::Overridden, $this->humanDecision($review)->relation_to_recommendation);
    }

    public function test_a_late_result_cannot_undo_a_human_decision(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::AiAssisted);
        $auction = $this->submitForReview();
        $review = ContentReview::firstOrFail();

        $this->actingAs($this->decider(), 'sanctum')
            ->postJson("/api/admin/auctions/{$auction->public_id}/review", [
                'action' => 'approve',
                'reason' => 'Decided before the automated result arrived.',
            ])
            ->assertOk();

        app(FakeContentReviewProvider::class)->respondWith($this->rejectResultPayload());
        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);
        $this->assertSame(0, ContentReviewDecision::where('decided_by_type', DecisionActorType::Ai->value)
            ->whereIn('decision', ['approved', 'rejected'])
            ->count());
    }

    private function decide(User $user, ContentReview $review, string $decision, ?string $reason = null)
    {
        return $this->actingAs($user, 'sanctum')->postJson(
            "/api/admin/content-reviews/{$review->public_id}/decide",
            array_filter(['decision' => $decision, 'reason' => $reason], static fn ($value): bool => $value !== null)
        );
    }

    private function decider(): User
    {
        return $this->auctionReviewer();
    }

    private function overrider(): User
    {
        return $this->auctionReviewer();
    }

    private function humanDecisionCount(): int
    {
        return ContentReviewDecision::where('decided_by_type', DecisionActorType::Admin->value)->count();
    }

    private function humanDecision(ContentReview $review): ContentReviewDecision
    {
        return ContentReviewDecision::where('review_id', $review->id)
            ->where('decided_by_type', DecisionActorType::Admin->value)
            ->firstOrFail();
    }

    /**
     * @return array{0: ContentReview, 1: Auction}
     */
    private function assistedReview(?array $payload = null, ReviewMode $mode = ReviewMode::AiAssisted): array
    {
        $this->publishPolicy();
        $this->publishSettings($mode);
        $auction = $this->submitForReview();

        app(FakeContentReviewProvider::class)->respondWith($payload ?? $this->cleanResultPayload());
        $review = ContentReview::firstOrFail();
        app(ProcessContentReviewAction::class)->execute((string) $review->public_id);

        return [$review->refresh(), $auction->refresh()];
    }

    private function rejectResultPayload(): array
    {
        return array_replace($this->cleanResultPayload(), [
            'recommendation' => 'reject',
            'confidence' => 94,
            'risk_level' => 'high',
            'summary_ar' => 'الإعلان يخالف سياسة المحتوى.',
            'summary_en' => 'The listing breaches the content policy.',
            'violations' => [[
                'code' => 'prohibited_item',
                'severity' => 'high',
                'field' => 'description',
                'evidence' => 'prohibited item mentioned',
            ]],
        ]);
    }
}
