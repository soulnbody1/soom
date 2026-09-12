<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\AdImage;
use App\Models\AdView;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Favorite;
use App\Models\UserAdInteraction;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Characterizes the unauthenticated read surface:
 * GET /api/soom/ads, /api/soom/ads/{id}, /api/soom/ads/category/{id}, /api/soom/home.
 */
final class AdPublicEndpointsTest extends AdTestCase
{
    use RefreshDatabase;

    public function test_listing_returns_the_standard_envelope_and_resource_keys(): void
    {
        $category = $this->category();
        $this->makeAds(3, ['category_id' => $category->id]);

        $response = $this->getJson('/api/soom/ads');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['*' => $this->adResourceKeys()],
                'current_page', 'last_page', 'per_page', 'total',
                'next_page_url', 'prev_page_url',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertSame(20, $response->json('per_page'));
        $this->assertSame(3, $response->json('total'));
    }

    public function test_listing_orders_newest_first_and_paginates_at_twenty(): void
    {
        // Distinct timestamps: with identical ones the current ORDER BY created_at
        // has no deterministic tie-break (see AdKnownDefectsTest).
        collect(range(1, 25))->each(fn (int $minute) => $this->makeAd([
            'created_at' => now()->subMinutes(30 - $minute),
        ]));

        $first = $this->getJson('/api/soom/ads?page=1');
        $second = $this->getJson('/api/soom/ads?page=2');

        $this->assertCount(20, $first->json('data'));
        $this->assertCount(5, $second->json('data'));

        $firstIds = collect($first->json('data'))->pluck('id');
        $this->assertEmpty($firstIds->intersect(collect($second->json('data'))->pluck('id')));
        $this->assertSame($firstIds->sortDesc()->values()->all(), $firstIds->values()->all());
    }

    public function test_listing_filters_by_price_location_and_category(): void
    {
        $wanted = $this->category();
        $other = $this->category();

        $this->makeAd(['category_id' => $wanted->id, 'price' => 100]);
        $this->makeAd(['category_id' => $wanted->id, 'price' => 900]);
        $this->makeAd(['category_id' => $other->id, 'price' => 100]);

        $this->assertSame(2, $this->getJson('/api/soom/ads?category_id='.$wanted->id)->json('total'));
        $this->assertSame(2, $this->getJson('/api/soom/ads?price_min=50&price_max=200')->json('total'));
        $this->assertSame(3, $this->getJson('/api/soom/ads?country_id='.$this->country()->id)->json('total'));
        $this->assertSame(1, $this->getJson('/api/soom/ads?category_id='.$wanted->id.'&price_min=500')->json('total'));
    }

    public function test_listing_category_filter_includes_descendant_categories(): void
    {
        $parent = $this->category();
        $child = $this->category($parent->id);
        $grandchild = $this->category($child->id);

        $this->makeAd(['category_id' => $parent->id]);
        $this->makeAd(['category_id' => $child->id]);
        $this->makeAd(['category_id' => $grandchild->id]);

        $this->assertSame(3, $this->getJson('/api/soom/ads?category_id='.$parent->id)->json('total'));
        $this->assertSame(2, $this->getJson('/api/soom/ads?category_id='.$child->id)->json('total'));
    }

    public function test_listing_filters_by_attribute_values(): void
    {
        $colour = Attribute::factory()->create(['name' => 'colour']);
        $red = $this->makeAd();
        $blue = $this->makeAd();

        AttributeValue::factory()->create(['ad_id' => $red->id, 'attribute_id' => $colour->id, 'value' => 'red']);
        AttributeValue::factory()->create(['ad_id' => $blue->id, 'attribute_id' => $colour->id, 'value' => 'blue']);

        $response = $this->getJson('/api/soom/ads?attributes['.$colour->id.']=red');

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($red->id, $response->json('data.0.id'));
    }

    public function test_listing_excludes_soft_deleted_ads(): void
    {
        $this->makeAds(2);
        $this->makeAd()->delete();

        $this->assertSame(2, $this->getJson('/api/soom/ads')->json('total'));
    }

    public function test_guest_always_sees_is_favorite_false(): void
    {
        $ad = $this->makeAd();
        Favorite::factory()->create(['ad_id' => $ad->id, 'user_id' => $this->adUser()->id]);

        $this->assertFalse($this->getJson('/api/soom/ads')->json('data.0.is_favorite'));
    }

    public function test_show_returns_the_full_resource_for_a_guest(): void
    {
        $ad = $this->makeAd();
        AdImage::factory()->create(['ad_id' => $ad->id]);

        $attribute = Attribute::factory()->create(['name' => 'condition']);
        AttributeValue::factory()->create([
            'ad_id' => $ad->id,
            'attribute_id' => $attribute->id,
            'value' => 'new',
        ]);

        $response = $this->getJson('/api/soom/ads/'.$ad->id);

        $response->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => $this->adResourceKeys()]);

        $this->assertSame($ad->id, $response->json('data.id'));
        $this->assertSame($ad->title, $response->json('data.title'));
        $this->assertTrue($response->json('data.status'));
        $this->assertCount(1, $response->json('data.images'));
        $this->assertSame('condition', $response->json('data.attributes.0.attribute'));
    }

    public function test_show_404s_for_a_missing_ad(): void
    {
        $this->getJson('/api/soom/ads/999999')->assertNotFound();
    }

    public function test_show_records_a_view_and_a_click_for_an_authenticated_user(): void
    {
        $ad = $this->makeAd();
        $viewer = $this->adUser();

        $this->actingAs($viewer, 'sanctum')->getJson('/api/soom/ads/'.$ad->id)->assertOk();

        $this->assertDatabaseHas('ad_views', ['ad_id' => $ad->id, 'user_id' => $viewer->id]);
        $this->assertDatabaseHas('user_ad_interactions', [
            'ad_id' => $ad->id,
            'user_id' => $viewer->id,
            'action' => 'click',
        ]);
    }

    public function test_show_is_idempotent_for_repeated_views_by_the_same_user(): void
    {
        $ad = $this->makeAd();
        $viewer = $this->adUser();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($viewer, 'sanctum')->getJson('/api/soom/ads/'.$ad->id)->assertOk();
        }

        $this->assertSame(1, AdView::where('ad_id', $ad->id)->count());
        $this->assertSame(1, UserAdInteraction::where('ad_id', $ad->id)->count());
    }

    public function test_category_endpoint_returns_ads_subcategories_and_nested_meta(): void
    {
        $parent = $this->category();
        $child = $this->category($parent->id);

        $this->makeAd(['category_id' => $parent->id]);
        $this->makeAd(['category_id' => $child->id]);

        $response = $this->getJson('/api/soom/ads/category/'.$parent->id);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'ads' => ['*' => $this->adResourceKeys()],
                    'nearby_ads',
                    'subcategories',
                    'meta' => ['current_page', 'last_page', 'per_page', 'total', 'next_page_url', 'prev_page_url'],
                ],
            ]);

        $this->assertSame(2, $response->json('data.meta.total'));
        $this->assertSame(10, $response->json('data.meta.per_page'));
        $this->assertCount(1, $response->json('data.subcategories'));
        $this->assertSame($child->id, $response->json('data.subcategories.0.id'));
        $this->assertSame(1, $response->json('data.subcategories.0.ads_count'));
        $this->assertSame([], $response->json('data.nearby_ads'));
    }

    public function test_category_endpoint_returns_nearby_ads_for_a_user_with_a_city(): void
    {
        // getNearbyAds orders with the MySQL-only FIELD() function, so this path
        // cannot run on SQLite today. Removing that raw ordering is part of the refactor.
        $this->requireMysql('getNearbyAds orders with the MySQL FIELD() function');

        $category = $this->category();
        $this->makeAds(2, ['category_id' => $category->id]);

        $response = $this->actingAs($this->adUser(), 'sanctum')
            ->getJson('/api/soom/ads/category/'.$category->id);

        $response->assertOk();
        $this->assertCount(2, $response->json('data.nearby_ads'));
    }

    public function test_home_groups_ads_under_their_root_category(): void
    {
        $cars = $this->category(null, 'cars');
        $sedans = $this->category($cars->id, 'sedans');
        $phones = $this->category(null, 'phones');

        $this->makeAds(3, ['category_id' => $cars->id]);
        $this->makeAds(3, ['category_id' => $sedans->id]);
        $this->makeAds(2, ['category_id' => $phones->id]);

        $response = $this->getJson('/api/soom/home');

        $response->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['*' => ['category', 'ads']]]);

        $groups = collect($response->json('data'))->keyBy('category');

        $this->assertSame(['cars', 'phones'], $groups->keys()->sort()->values()->all());
        $this->assertCount(4, $groups['cars']['ads'], 'Home caps each root category at four ads.');
        $this->assertCount(2, $groups['phones']['ads']);
    }

    public function test_home_ad_rows_carry_complete_card_metadata(): void
    {
        $category = $this->category(null, 'furniture');
        $ad = $this->makeAd(['category_id' => $category->id]);
        AdImage::factory()->count(3)->create(['ad_id' => $ad->id]);
        AdView::factory()->count(2)->create(['ad_id' => $ad->id]);

        $row = $this->getJson('/api/soom/home')->json('data.0.ads.0');

        $this->assertSame([
            'id', 'title', 'description', 'price', 'category', 'category_id', 'location',
            'user', 'image', 'images_count', 'created_at', 'views_count', 'is_favorite',
            'is_featured',
        ], array_keys($row));
        $this->assertSame($category->id, $row['category_id']);
        $this->assertSame(3, $row['images_count']);
        $this->assertNotNull($row['created_at']);
        $this->assertSame($ad->user->name, $row['user']['name']);
        $this->assertSame(
            implode(', ', [$this->country()->name, $this->state()->name, $this->city()->name]),
            $row['location']
        );
        $this->assertSame(2, $row['views_count']);
        $this->assertArrayHasKey('image', $row);
        $this->assertArrayNotHasKey('images', $row);
        $this->assertNotNull($row['image']);
    }

    public function test_home_groups_expose_their_root_category_id(): void
    {
        $root = $this->category(null, 'vehicles');
        $this->makeAd(['category_id' => $root->id]);

        $group = collect($this->getJson('/api/soom/home')->json('data'))
            ->firstWhere('category', 'vehicles');

        $this->assertSame($root->id, $group['category_id']);
    }

    public function test_listing_sorts_by_the_requested_whitelisted_key(): void
    {
        $category = $this->category();
        $cheap = $this->makeAd(['category_id' => $category->id, 'price' => 100]);
        $expensive = $this->makeAd(['category_id' => $category->id, 'price' => 900]);

        $ascending = $this->getJson('/api/soom/ads?sort=price_asc')->json('data.*.id');
        $descending = $this->getJson('/api/soom/ads?sort=price_desc')->json('data.*.id');

        $this->assertSame($cheap->id, $ascending[0]);
        $this->assertSame($expensive->id, $descending[0]);
    }

    public function test_listing_orders_by_authoritative_view_totals(): void
    {
        $category = $this->category();
        $quiet = $this->makeAd(['category_id' => $category->id]);
        $popular = $this->makeAd(['category_id' => $category->id]);
        AdView::factory()->count(5)->create(['ad_id' => $popular->id]);
        AdView::factory()->create(['ad_id' => $quiet->id]);

        $rows = $this->getJson('/api/soom/ads?sort=most_viewed')->json('data');

        $this->assertSame($popular->id, $rows[0]['id']);
        $this->assertSame(5, $rows[0]['views_count']);
        $this->assertSame(1, $rows[1]['views_count']);
    }

    public function test_listing_honours_a_bounded_page_size_and_rejects_invalid_input(): void
    {
        $category = $this->category();
        $this->makeAd(['category_id' => $category->id]);
        $this->makeAd(['category_id' => $category->id]);
        $this->makeAd(['category_id' => $category->id]);

        $response = $this->getJson('/api/soom/ads?per_page=2');

        $this->assertSame(2, $response->json('per_page'));
        $this->assertCount(2, $response->json('data'));
        $this->getJson('/api/soom/ads?per_page=500')->assertStatus(422);
        $this->getJson('/api/soom/ads?sort=bogus')->assertStatus(422);
    }

    public function test_listing_and_detail_expose_category_and_location_identifiers(): void
    {
        $category = $this->category();
        $ad = $this->makeAd(['category_id' => $category->id]);

        foreach ([$this->getJson('/api/soom/ads')->json('data.0'), $this->getJson('/api/soom/ads/'.$ad->id)->json('data')] as $row) {
            $this->assertSame($category->id, $row['category_id']);
            $this->assertSame($ad->country_id, $row['country_id']);
            $this->assertSame($ad->state_id, $row['state_id']);
            $this->assertSame($ad->city_id, $row['city_id']);
        }
    }

    public function test_home_omits_ads_for_root_categories_that_have_none(): void
    {
        $withAds = $this->category(null, 'withads');
        $this->category(null, 'emptycat');
        $this->makeAd(['category_id' => $withAds->id]);

        $groups = collect($this->getJson('/api/soom/home')->json('data'))->keyBy('category');

        $this->assertCount(1, $groups['withads']['ads']);
        $this->assertSame([], $groups['emptycat']['ads']);
    }

    public function test_public_endpoints_never_expose_soft_deleted_ads(): void
    {
        $category = $this->category();
        $ad = $this->makeAd(['category_id' => $category->id]);
        $ad->delete();

        $this->assertSame(0, $this->getJson('/api/soom/ads')->json('total'));
        $this->assertSame(0, $this->getJson('/api/soom/ads/category/'.$category->id)->json('data.meta.total'));
        $this->getJson('/api/soom/ads/'.$ad->id)->assertNotFound();
    }

    public function test_listing_exposes_the_seller_contact_block(): void
    {
        $ad = $this->makeAd();

        $user = $this->getJson('/api/soom/ads')->json('data.0.user');

        $this->assertSame(
            ['id', 'name', 'phone', 'logo'],
            array_keys($user),
            'The seller block is part of the published contract and must stay stable.'
        );
        $this->assertSame($ad->user_id, $user['id']);
    }
}
