<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\Ad;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Characterizes /api/admin/ads (list plus the moderation actions).
 *
 * Pagination metadata for the list itself is already covered by AdminAdsListingTest.
 */
final class AdminAdEndpointsTest extends AdTestCase
{
    use RefreshDatabase;

    public function test_listing_returns_featured_ads_first(): void
    {
        $this->makeAds(2);
        $featured = $this->makeAd(['is_featured' => true]);

        $data = $this->actingAs($this->adUser('admin'), 'sanctum')
            ->getJson('/api/admin/ads')
            ->assertOk()
            ->json('data');

        $this->assertSame($featured->id, $data[0]['id']);
        $this->assertTrue($data[0]['is_featured']);
    }

    public function test_listing_includes_soft_deleted_ads_and_flags_their_status(): void
    {
        $this->makeAd();
        $blocked = $this->makeAd();
        $blocked->delete();

        $rows = collect(
            $this->actingAs($this->adUser('admin'), 'sanctum')->getJson('/api/admin/ads')->json('data')
        )->keyBy('id');

        $this->assertCount(2, $rows);
        $this->assertFalse($rows[$blocked->id]['status']);
    }

    public function test_listing_is_admin_only(): void
    {
        $this->getJson('/api/admin/ads')->assertUnauthorized();

        $this->actingAs($this->adUser(), 'sanctum')
            ->getJson('/api/admin/ads')
            ->assertForbidden();
    }

    public function test_toggle_block_soft_deletes_then_restores(): void
    {
        $admin = $this->adUser('admin');
        $ad = $this->makeAd();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/ads/toggle-block/'.$ad->id)
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertSoftDeleted('ads', ['id' => $ad->id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/ads/toggle-block/'.$ad->id)
            ->assertOk();

        $this->assertDatabaseHas('ads', ['id' => $ad->id, 'deleted_at' => null]);
    }

    public function test_toggle_block_404s_for_a_missing_ad(): void
    {
        $this->actingAs($this->adUser('admin'), 'sanctum')
            ->putJson('/api/admin/ads/toggle-block/999999')
            ->assertNotFound();
    }

    public function test_toggle_featured_flips_the_flag_both_ways(): void
    {
        $admin = $this->adUser('admin');
        $ad = $this->makeAd();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/ads/toggle-featured/'.$ad->id)
            ->assertOk();

        $this->assertTrue((bool) $ad->fresh()->is_featured);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/ads/toggle-featured/'.$ad->id)
            ->assertOk();

        $this->assertFalse((bool) $ad->fresh()->is_featured);
    }

    public function test_admin_force_delete_removes_any_ad(): void
    {
        $ad = $this->makeAd();

        $this->actingAs($this->adUser('admin'), 'sanctum')
            ->deleteJson('/api/admin/ads/force-delete/'.$ad->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('ads', ['id' => $ad->id]);
    }

    public function test_admin_force_delete_404s_for_a_missing_ad(): void
    {
        $this->actingAs($this->adUser('admin'), 'sanctum')
            ->deleteJson('/api/admin/ads/force-delete/999999')
            ->assertNotFound();
    }

    public function test_moderation_actions_are_admin_only(): void
    {
        $ad = $this->makeAd();
        $user = $this->adUser();

        $this->actingAs($user, 'sanctum')->putJson('/api/admin/ads/toggle-block/'.$ad->id)->assertForbidden();
        $this->actingAs($user, 'sanctum')->putJson('/api/admin/ads/toggle-featured/'.$ad->id)->assertForbidden();
        $this->actingAs($user, 'sanctum')->deleteJson('/api/admin/ads/force-delete/'.$ad->id)->assertForbidden();

        $this->assertDatabaseHas('ads', ['id' => $ad->id, 'deleted_at' => null]);
        $this->assertSame(0, Ad::where('is_featured', true)->count());
    }
}
