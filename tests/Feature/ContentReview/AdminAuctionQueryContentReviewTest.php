<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\Auction;
use App\Models\ContentReview\ContentReview;
use App\Services\ContentReview\Actions\ProcessContentReviewAction;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class AdminAuctionQueryContentReviewTest extends TestCase
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

    public function test_the_admin_list_query_count_does_not_grow_with_the_number_of_reviews(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $admin = $this->fullyPermittedAdmin();

        $this->reviewedAuctions(1);
        $withOne = $this->countQueries(fn () => $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions')
            ->assertOk());

        $this->reviewedAuctions(5);
        $withSix = $this->countQueries(fn () => $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions')
            ->assertOk());

        $this->assertSame(6, Auction::count());
        $this->assertSame(6, ContentReview::count());
        $this->assertSame(
            $withOne,
            $withSix,
            "The admin list ran {$withOne} queries for one review and {$withSix} for six."
        );
    }

    public function test_the_list_loads_the_active_review_only_for_a_permitted_admin(): void
    {
        config()->set('content_review.role_admin_permissions', []);
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);
        $this->reviewedAuctions(3);

        $permitted = $this->admin(['auction.review', 'content_review.view']);
        $withoutPermission = $this->admin(['auction.review']);

        $withBlock = $this->countQueries(fn () => $this->actingAs($permitted, 'sanctum')
            ->getJson('/api/admin/auctions')
            ->assertOk()
            ->assertJsonPath('data.0.ai_review.enabled', true));

        $withoutBlock = $this->countQueries(fn () => $this->actingAs($withoutPermission, 'sanctum')
            ->getJson('/api/admin/auctions')
            ->assertOk());

        $this->assertGreaterThan(
            $withoutBlock,
            $withBlock,
            'The permitted admin must trigger the extra eager load, the other must not.'
        );
    }

    public function test_the_pending_review_action_becomes_awaiting_ai_review_while_the_ai_is_working(): void
    {
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);

        $auction = $this->submitForReview();
        $admin = $this->fullyPermittedAdmin();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/auctions/{$auction->public_id}")
            ->assertOk()
            ->assertJsonPath('data.next_admin_action', 'awaiting_ai_review');

        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        app(ProcessContentReviewAction::class)->execute((string) ContentReview::firstOrFail()->public_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/auctions/{$auction->public_id}")
            ->assertOk()
            ->assertJsonPath('data.next_admin_action', 'review_auction');
    }

    public function test_an_admin_without_the_permission_still_sees_the_original_next_action(): void
    {
        config()->set('content_review.role_admin_permissions', []);
        $this->publishPolicy();
        $this->publishSettings(ReviewMode::Shadow);

        $auction = $this->submitForReview();

        $this->actingAs($this->admin(['auction.review']), 'sanctum')
            ->getJson("/api/admin/auctions/{$auction->public_id}")
            ->assertOk()
            ->assertJsonPath('data.next_admin_action', 'review_auction');
    }

    private function reviewedAuctions(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->submitForReview();
        }
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }

    private function fakeProvider(): FakeContentReviewProvider
    {
        return app(FakeContentReviewProvider::class);
    }
}
