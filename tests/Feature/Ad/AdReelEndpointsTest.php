<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\AdReel;
use App\Models\AdReelView;
use App\Models\Favorite;

/**
 * Characterizes the reel feed and reel-view endpoints.
 */
final class AdReelEndpointsTest extends AdTestCase
{
    public function test_reels_feed_returns_a_paginated_payload(): void
    {
        $this->makeReels(3);

        $response = $this->getJson('/api/soom/ads/reels');

        $response->assertOk()
            ->assertJsonStructure([
                'all_reels' => ['data', 'current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertSame(3, $response->json('all_reels.total'));
        $this->assertSame(10, $response->json('all_reels.per_page'));
    }

    public function test_reels_feed_hides_the_viewers_own_reels(): void
    {
        $viewer = $this->adUser();

        $this->makeReels(2);
        $this->makeReels(1, ['user_id' => $viewer->id]);

        $response = $this->actingAs($viewer, 'sanctum')->getJson('/api/soom/ads/reels');

        $this->assertSame(2, $response->json('all_reels.total'));
    }

    public function test_reels_feed_excludes_reels_whose_ad_is_soft_deleted(): void
    {
        $reels = $this->makeReels(2);
        $reels->first()->ad->delete();

        $this->assertSame(1, $this->getJson('/api/soom/ads/reels')->json('all_reels.total'));
    }

    public function test_reels_feed_marks_favorited_ads_for_the_viewer(): void
    {
        $viewer = $this->adUser();
        $reel = $this->makeReels(1)->first();

        Favorite::factory()->create(['user_id' => $viewer->id, 'ad_id' => $reel->ad_id]);

        $row = $this->actingAs($viewer, 'sanctum')->getJson('/api/soom/ads/reels')->json('all_reels.data.0');

        // The published field is spelled "is_favorit" (no trailing e). It is part of
        // the shipped contract, so the refactor must keep the misspelling.
        $this->assertArrayHasKey('is_favorit', $row);
        $this->assertSame(1, $row['is_favorit']);
    }

    public function test_category_reels_feed_is_scoped_to_that_category_tree(): void
    {
        $parent = $this->category();
        $child = $this->category($parent->id);
        $unrelated = $this->category();

        $this->makeReels(1, ['category_id' => $parent->id]);
        $this->makeReels(1, ['category_id' => $child->id]);
        $this->makeReels(1, ['category_id' => $unrelated->id]);

        $response = $this->getJson('/api/soom/ads/reels/'.$parent->id);

        $response->assertOk();
        $this->assertSame(2, $response->json('all_reels.total'));
    }

    public function test_storing_a_reel_view_returns_the_view_resource(): void
    {
        $viewer = $this->adUser();
        $reel = $this->makeReels(1)->first();

        $response = $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/soom/ads/ad-reel-views', ['ad_reel_id' => $reel->id]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'ad_reel_id', 'user_id', 'viewed_at']]);

        $this->assertDatabaseHas('ad_reel_views', [
            'ad_reel_id' => $reel->id,
            'user_id' => $viewer->id,
        ]);
    }

    public function test_storing_a_duplicate_reel_view_is_reported_as_already_seen(): void
    {
        $viewer = $this->adUser();
        $reel = $this->makeReels(1)->first();

        AdReelView::factory()->create(['ad_reel_id' => $reel->id, 'user_id' => $viewer->id]);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/soom/ads/ad-reel-views', ['ad_reel_id' => $reel->id])
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertSame(1, AdReelView::where('ad_reel_id', $reel->id)->count());
    }

    public function test_storing_a_reel_view_validates_the_reel_id(): void
    {
        $this->actingAs($this->adUser(), 'sanctum')
            ->postJson('/api/soom/ads/ad-reel-views', ['ad_reel_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ad_reel_id']);
    }

    public function test_owner_can_delete_their_reel(): void
    {
        $owner = $this->adUser();
        $reel = $this->makeReels(1, ['user_id' => $owner->id])->first();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/soom/ads/my/reel/'.$reel->id)
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('ad_reels', ['id' => $reel->id]);
    }

    public function test_deleting_another_users_reel_is_forbidden(): void
    {
        $reel = $this->makeReels(1)->first();

        $this->actingAs($this->adUser(), 'sanctum')
            ->deleteJson('/api/soom/ads/my/reel/'.$reel->id)
            ->assertForbidden();

        $this->assertDatabaseHas('ad_reels', ['id' => $reel->id]);
    }

    public function test_deleting_a_missing_reel_404s(): void
    {
        $this->actingAs($this->adUser(), 'sanctum')
            ->deleteJson('/api/soom/ads/my/reel/999999')
            ->assertNotFound();
    }

    /**
     * @return \Illuminate\Support\Collection<int, AdReel>
     */
    private function makeReels(int $count, array $adOverrides = [])
    {
        return $this->makeAds($count, $adOverrides)
            ->map(fn ($ad) => AdReel::factory()->create(['ad_id' => $ad->id]));
    }
}
