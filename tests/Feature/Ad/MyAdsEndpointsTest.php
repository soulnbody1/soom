<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Jobs\ProcessAdReel;
use App\Jobs\SendAdNotification;
use App\Models\Ad;
use App\Models\AdImage;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Characterizes the owner-facing write surface under /api/soom/ads.
 */
final class MyAdsEndpointsTest extends AdTestCase
{
    public function test_store_creates_an_ad_with_images_attributes_and_returns_the_bare_resource(): void
    {
        Queue::fake();

        $owner = $this->adUser();
        $attribute = Attribute::factory()->create(['name' => 'colour']);

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/soom/ads', [
            'title' => 'A bicycle',
            'description' => 'Barely used',
            'price' => 250.5,
            'category_id' => $this->category()->id,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
            'attributes' => [['id' => $attribute->id, 'value' => 'red']],
            'images' => [UploadedFile::fake()->image('bike.jpg')],
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => $this->adResourceKeys()]);

        // store() returns the resource directly, with no success/message envelope.
        $this->assertNull($response->json('success'));

        $ad = Ad::firstWhere('title', 'A bicycle');
        $this->assertNotNull($ad);
        $this->assertSame($owner->id, $ad->user_id);
        $this->assertSame(1, $ad->images()->count());
        $this->assertSame(1, $ad->attributeValues()->count());
        $this->assertSame('red', $ad->attributeValues()->first()->value);

        Queue::assertPushed(SendAdNotification::class);
    }

    public function test_store_stores_multi_value_attributes_as_one_row_per_value(): void
    {
        Queue::fake();

        $attribute = Attribute::factory()->create(['name' => 'features']);

        $this->actingAs($this->adUser(), 'sanctum')->postJson('/api/soom/ads', [
            'title' => 'A car',
            'description' => 'Loaded',
            'price' => 1000,
            'category_id' => $this->category()->id,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
            'attributes' => [['id' => $attribute->id, 'value' => ['abs', 'airbag']]],
            'images' => [UploadedFile::fake()->image('car.jpg')],
        ])->assertCreated();

        $this->assertSame(2, AttributeValue::where('attribute_id', $attribute->id)->count());
    }

    public function test_store_dispatches_reel_processing_when_a_video_is_attached(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->actingAs($this->adUser(), 'sanctum')->postJson('/api/soom/ads', [
            'title' => 'With a reel',
            'description' => 'Video attached',
            'price' => 10,
            'category_id' => $this->category()->id,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
            'images' => [UploadedFile::fake()->image('a.jpg')],
            'reel_video' => UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'),
        ])->assertCreated();

        Queue::assertPushed(ProcessAdReel::class);
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/soom/ads', [])->assertUnauthorized();
    }

    public function test_store_rejects_a_payload_without_images(): void
    {
        $this->actingAs($this->adUser(), 'sanctum')->postJson('/api/soom/ads', [
            'title' => 'No images',
            'description' => 'Missing media',
            'price' => 10,
            'category_id' => $this->category()->id,
            'country_id' => $this->country()->id,
        ])->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->adUser(), 'sanctum')
            ->postJson('/api/soom/ads', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'description', 'price', 'category_id', 'country_id']);
    }

    public function test_update_replaces_attributes_and_images_for_the_owner(): void
    {
        Queue::fake();

        $owner = $this->adUser();
        $ad = $this->makeAd(['user_id' => $owner->id]);
        AdImage::factory()->create(['ad_id' => $ad->id]);

        $attribute = Attribute::factory()->create(['name' => 'size']);
        AttributeValue::factory()->create([
            'ad_id' => $ad->id,
            'attribute_id' => $attribute->id,
            'value' => 'small',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->putJson('/api/soom/ads/my/'.$ad->id, [
            'title' => 'Updated title',
            'description' => 'Updated description',
            'price' => 42,
            'category_id' => $ad->category_id,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
            'attributes' => [['id' => $attribute->id, 'value' => 'large']],
            'images' => [UploadedFile::fake()->image('new.jpg')],
        ]);

        $response->assertOk()->assertJsonStructure(['data' => $this->adResourceKeys()]);

        $ad->refresh();
        $this->assertSame('Updated title', $ad->title);
        $this->assertSame(1, $ad->images()->count());
        $this->assertSame('large', $ad->attributeValues()->first()->value);
    }

    public function test_update_is_forbidden_for_a_non_owner(): void
    {
        $ad = $this->makeAd(['user_id' => $this->adUser()->id]);

        $this->actingAs($this->adUser(), 'sanctum')->putJson('/api/soom/ads/my/'.$ad->id, [
            'title' => 'Hijacked',
            'description' => 'Not mine',
            'price' => 1,
            'category_id' => $ad->category_id,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
            'images' => [UploadedFile::fake()->image('x.jpg')],
        ])->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_my_ads_returns_owned_ads_with_view_totals(): void
    {
        $owner = $this->adUser();
        $ads = $this->makeAds(3, ['user_id' => $owner->id]);
        $this->makeAd();

        \App\Models\AdView::factory()->create(['ad_id' => $ads->first()->id, 'user_id' => $owner->id]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/soom/ads/my');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'total_views',
                'data' => ['*' => ['id', 'title', 'description', 'price', 'category', 'location', 'user', 'images', 'views_count']],
            ]);

        $this->assertCount(3, $response->json('data'));
        $this->assertSame(1, $response->json('total_views'));
    }

    public function test_my_ads_includes_soft_deleted_ads(): void
    {
        $owner = $this->adUser();
        $ads = $this->makeAds(2, ['user_id' => $owner->id]);
        $ads->first()->delete();

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/soom/ads/my');

        $this->assertCount(2, $response->json('data'));
    }

    public function test_soft_delete_restore_and_force_delete_round_trip(): void
    {
        $owner = $this->adUser();
        $ad = $this->makeAd(['user_id' => $owner->id]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/soom/ads/my/soft-delete/'.$ad->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('ads', ['id' => $ad->id]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/soom/ads/my/restore/'.$ad->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('ads', ['id' => $ad->id, 'deleted_at' => null]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/soom/ads/my/force-delete/'.$ad->id)
            ->assertOk();

        $this->assertDatabaseMissing('ads', ['id' => $ad->id]);
    }

    public function test_restore_rejects_an_ad_that_is_not_deleted(): void
    {
        $owner = $this->adUser();
        $ad = $this->makeAd(['user_id' => $owner->id]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/soom/ads/my/restore/'.$ad->id)
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_owner_scoped_routes_404_for_another_users_ad(): void
    {
        $ad = $this->makeAd(['user_id' => $this->adUser()->id]);
        $stranger = $this->adUser();

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/soom/ads/my/restore/'.$ad->id)
            ->assertNotFound();

        $this->actingAs($stranger, 'sanctum')
            ->deleteJson('/api/soom/ads/my/force-delete/'.$ad->id)
            ->assertNotFound();
    }

    public function test_soft_delete_is_forbidden_for_a_non_owner(): void
    {
        $ad = $this->makeAd(['user_id' => $this->adUser()->id]);

        $this->actingAs($this->adUser(), 'sanctum')
            ->deleteJson('/api/soom/ads/my/soft-delete/'.$ad->id)
            ->assertForbidden();
    }
}
