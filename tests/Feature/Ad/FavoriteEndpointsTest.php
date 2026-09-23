<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\Favorite;
use App\Models\UserAdInteraction;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Characterizes /api/soom/favorites.
 */
final class FavoriteEndpointsTest extends AdTestCase
{
    use RefreshDatabase;

    public function test_index_returns_the_users_favorites_with_the_nested_ad(): void
    {
        $user = $this->adUser();
        $ads = $this->makeAds(2);

        $ads->each(fn ($ad) => Favorite::factory()->create(['user_id' => $user->id, 'ad_id' => $ad->id]));
        Favorite::factory()->create(['user_id' => $this->adUser()->id, 'ad_id' => $ads->first()->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/soom/favorites');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['*' => ['id', 'ad_id', 'ad' => $this->adResourceKeys(), 'added_at']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(10, $response->json('meta.per_page'));
    }

    public function test_index_hides_favorites_whose_ad_was_soft_deleted(): void
    {
        $user = $this->adUser();
        $ads = $this->makeAds(2);
        $ads->each(fn ($ad) => Favorite::factory()->create(['user_id' => $user->id, 'ad_id' => $ad->id]));

        $ads->first()->delete();

        $this->assertSame(
            1,
            $this->actingAs($user, 'sanctum')->getJson('/api/soom/favorites')->json('meta.total')
        );
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/soom/favorites')->assertUnauthorized();
    }

    public function test_store_adds_a_favorite_and_records_a_save_interaction(): void
    {
        $user = $this->adUser();
        $ad = $this->makeAd();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/favorites', ['ad_id' => $ad->public_id])
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'ad_id' => $ad->id]);
        $this->assertDatabaseHas('user_ad_interactions', [
            'user_id' => $user->id,
            'ad_id' => $ad->id,
            'action' => 'save',
        ]);
    }

    public function test_store_conflicts_when_the_ad_is_already_favorited(): void
    {
        $user = $this->adUser();
        $ad = $this->makeAd();
        Favorite::factory()->create(['user_id' => $user->id, 'ad_id' => $ad->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/favorites', ['ad_id' => $ad->public_id])
            ->assertStatus(409);

        $this->assertSame(1, Favorite::where('user_id', $user->id)->count());
    }

    public function test_store_records_the_save_interaction_only_once(): void
    {
        $user = $this->adUser();
        $ad = $this->makeAd();

        $this->actingAs($user, 'sanctum')->postJson('/api/soom/favorites', ['ad_id' => $ad->public_id]);
        $this->actingAs($user, 'sanctum')->deleteJson('/api/soom/favorites/'.$ad->public_id);
        $this->actingAs($user, 'sanctum')->postJson('/api/soom/favorites', ['ad_id' => $ad->public_id]);

        $this->assertSame(
            1,
            UserAdInteraction::where(['user_id' => $user->id, 'ad_id' => $ad->id, 'action' => 'save'])->count()
        );
    }

    public function test_store_validates_the_ad_id(): void
    {
        $this->actingAs($this->adUser(), 'sanctum')
            ->postJson('/api/soom/favorites', ['ad_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ad_id']);
    }

    public function test_destroy_removes_the_favorite(): void
    {
        $user = $this->adUser();
        $ad = $this->makeAd();
        Favorite::factory()->create(['user_id' => $user->id, 'ad_id' => $ad->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/soom/favorites/'.$ad->public_id)
            ->assertOk();

        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'ad_id' => $ad->id]);
    }

    public function test_destroy_404s_when_the_ad_is_not_favorited(): void
    {
        $this->actingAs($this->adUser(), 'sanctum')
            ->deleteJson('/api/soom/favorites/'.$this->makeAd()->public_id)
            ->assertNotFound();
    }

    public function test_destroy_only_touches_the_callers_own_favorite(): void
    {
        $owner = $this->adUser();
        $other = $this->adUser();
        $ad = $this->makeAd();

        Favorite::factory()->create(['user_id' => $owner->id, 'ad_id' => $ad->id]);
        Favorite::factory()->create(['user_id' => $other->id, 'ad_id' => $ad->id]);

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/soom/favorites/'.$ad->public_id)->assertOk();

        $this->assertDatabaseMissing('favorites', ['user_id' => $owner->id, 'ad_id' => $ad->id]);
        $this->assertDatabaseHas('favorites', ['user_id' => $other->id, 'ad_id' => $ad->id]);
    }

    public function test_favoriting_is_reflected_in_the_listing_for_that_user_only(): void
    {
        $owner = $this->adUser();
        $stranger = $this->adUser();
        $ad = $this->makeAd();

        $this->actingAs($owner, 'sanctum')->postJson('/api/soom/favorites', ['ad_id' => $ad->public_id])->assertOk();

        $this->assertTrue(
            $this->actingAs($owner, 'sanctum')->getJson('/api/soom/ads')->json('data.0.is_favorite')
        );
        $this->assertFalse(
            $this->actingAs($stranger, 'sanctum')->getJson('/api/soom/ads')->json('data.0.is_favorite')
        );
    }
}
