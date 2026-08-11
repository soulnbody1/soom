<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionStatusHistory;
use App\Models\Auction\OutboxMessage;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;
use App\Models\User;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Actions\ProcessContentReviewAction;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class AiAssistedFlowTest extends TestCase
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

    public function test_an_assisted_review_never_moves_the_subject_on_its_own(): void
    {
        [$review, $auction] = $this->assistedReview();

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
        $this->assertSame('advisory_only', $review->outcome?->value);
        $this->assertTrue((bool) $review->requires_human_review);
        $this->assertSame(0, AuctionConfigurationSnapshot::where('auction_id', $auction->id)->count());
        $this->assertSame(0, AuctionDeposit::where('auction_id', $auction->id)->count());
        $this->assertSame(0, ContentReviewDecision::where('decided_by_type', DecisionActorType::Admin->value)->count());
    }

    public function test_an_admin_confirms_an_approve_recommendation(): void
    {
        [$review, $auction] = $this->assistedReview();
        $admin = $this->decider();

        $this->decide($admin, $review, 'approve')->assertOk();

        $auction->refresh();
        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->status);

        $snapshot = AuctionConfigurationSnapshot::where('auction_id', $auction->id)->firstOrFail();
        $this->assertSame((int) $admin->id, (int) $snapshot->created_by);

        $this->assertSame(1, AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->count());

        $decision = $this->humanDecision($review);
        $this->assertSame(ContentReviewDecisionType::Approved, $decision->decision);
        $this->assertSame(DecisionActorType::Admin, $decision->decided_by_type);
        $this->assertSame((int) $admin->id, (int) $decision->decided_by_id);
        $this->assertSame(DecisionRelation::Confirmed, $decision->relation_to_recommendation);
        $this->assertSame('approve', $decision->ai_recommendation?->value);
        $this->assertSame((int) $review->confidence, (int) $decision->ai_confidence);

        $history = AuctionStatusHistory::where('auction_id', $auction->id)
            ->where('to_status', AuctionStatus::AwaitingSellerDeposit->value)
            ->firstOrFail();
        $this->assertSame('admin', $history->actor_type);
        $this->assertSame((int) $admin->id, (int) $history->changed_by);
    }

    public function test_an_admin_confirms_a_reject_recommendation(): void
    {
        [$review, $auction] = $this->assistedReview($this->rejectResultPayload());
        $admin = $this->decider();

        $this->assertSame('reject', $review->recommendation?->value);

        $this->decide($admin, $review, 'reject', 'Prohibited item.')->assertOk();

        $this->assertSame(AuctionStatus::Rejected, $auction->refresh()->status);

        $decision = $this->humanDecision($review);
        $this->assertSame(DecisionRelation::Confirmed, $decision->relation_to_recommendation);
        $this->assertSame('Prohibited item.', $decision->reason);
        $this->assertSame('reject', $decision->ai_recommendation?->value);
    }

    public function test_a_confirmed_reject_without_a_reason_falls_back_to_the_summary(): void
    {
        [$review, $auction] = $this->assistedReview($this->rejectResultPayload());

        $this->decide($this->decider(), $review, 'reject')->assertOk();

        $history = AuctionStatusHistory::where('auction_id', $auction->id)
            ->where('to_status', AuctionStatus::Rejected->value)
            ->firstOrFail();

        $this->assertSame($review->summary_ar, $history->reason);
    }

    public function test_the_original_review_row_is_not_rewritten_by_a_human_decision(): void
    {
        [$review] = $this->assistedReview();
        $before = $this->reviewFingerprint($review);

        $this->decide($this->decider(), $review, 'approve')->assertOk();

        $this->assertSame($before, $this->reviewFingerprint($review->refresh()));
    }

    public function test_no_user_row_is_ever_created_for_the_artificial_intelligence(): void
    {
        $before = User::count();
        [$review] = $this->assistedReview();

        $this->decide($this->decider(), $review, 'approve')->assertOk();

        $this->assertSame($before + 2, User::count());
        $this->assertSame(0, ContentReviewDecision::whereNotNull('decided_by_id')
            ->where('decided_by_type', DecisionActorType::Ai->value)
            ->count());
    }

    public function test_a_second_confirmation_is_refused(): void
    {
        [$review] = $this->assistedReview();
        $admin = $this->decider();

        $this->decide($admin, $review, 'approve')->assertOk();

        $this->decide($admin, $review, 'approve')
            ->assertStatus(409)
            ->assertJsonPath('code', 'review_already_decided');

        $this->assertSame(1, ContentReviewDecision::where('decided_by_type', DecisionActorType::Admin->value)->count());
    }

    public function test_a_confirmation_publishes_exactly_one_event(): void
    {
        [$review] = $this->assistedReview();

        $this->decide($this->decider(), $review, 'approve')->assertOk();

        $this->assertSame(1, OutboxMessage::where('event_type', 'content_review.confirmed')->count());
        $this->assertSame(0, OutboxMessage::where('event_type', 'content_review.overridden')->count());
    }

    public function test_a_stale_review_cannot_be_confirmed(): void
    {
        [$review, $auction] = $this->assistedReview();

        $auction->forceFill(['title' => 'A completely different listing title'])->save();

        $this->decide($this->decider(), $review, 'approve')
            ->assertStatus(409)
            ->assertJsonPath('code', 'review_stale');

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_a_superseded_review_cannot_be_confirmed(): void
    {
        [$review, $auction] = $this->assistedReview();

        app(ContentReviewRepository::class)->deactivate($review);

        $this->decide($this->decider(), $review, 'approve')
            ->assertStatus(409)
            ->assertJsonPath('code', 'review_superseded');

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_a_shadow_review_cannot_be_confirmed_through_the_decide_endpoint(): void
    {
        [$review, $auction] = $this->assistedReview(null, ReviewMode::Shadow);

        $this->decide($this->decider(), $review, 'approve')
            ->assertStatus(422)
            ->assertJsonPath('code', 'review_not_assisted');

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_the_decide_endpoint_requires_the_subject_review_permission(): void
    {
        [$review, $auction] = $this->assistedReview();

        config()->set('auction.admin_permissions', []);
        $withoutAuctionRights = $this->admin();

        $this->decide($withoutAuctionRights, $review, 'approve')
            ->assertStatus(403)
            ->assertJsonPath('code', 'decision_not_permitted');

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    private function decide(User $user, ContentReview $review, string $decision, ?string $reason = null)
    {
        return $this->actingAs($user, 'sanctum')->postJson(
            "/api/admin/content-reviews/{$review->public_id}/decide",
            array_filter(['decision' => $decision, 'reason' => $reason], static fn ($value): bool => $value !== null)
        );
    }

    private function decider(array $extra = []): User
    {
        return $this->auctionReviewer($extra);
    }

    private function reviewFingerprint(ContentReview $review): array
    {
        return [
            'status' => $review->status->value,
            'outcome' => $review->outcome?->value,
            'recommendation' => $review->recommendation?->value,
            'confidence' => (int) $review->confidence,
            'reason_code' => $review->reason_code,
            'decided_at' => $review->decided_at?->toIso8601String(),
            'current_marker' => $review->current_marker,
        ];
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
