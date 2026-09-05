<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use Illuminate\Foundation\Testing\DatabaseTruncation;

/**
 * Characterizes the fulltext-backed search endpoints.
 *
 * Both use MATCH ... AGAINST, which has no SQLite equivalent, so the whole class
 * only runs on MySQL — via phpunit.ads-mysql.xml / `composer test:ads:mysql`.
 *
 * DatabaseTruncation rather than RefreshDatabase: InnoDB does not expose rows
 * written by an uncommitted transaction to its fulltext index, so a wrapped test
 * would search an empty index.
 */
final class AdSearchEndpointsTest extends AdTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMysql('search uses MATCH ... AGAINST');
    }

    public function test_search_by_title_returns_the_standard_paginated_envelope(): void
    {
        $this->makeAd(['title' => 'Vintage bicycle', 'description' => 'A classic ride']);
        $this->makeAd(['title' => 'Office chair', 'description' => 'Ergonomic']);

        $response = $this->getJson('/api/soom/ads/search?title=bicycle');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['*' => $this->adResourceKeys()],
                'current_page', 'last_page', 'per_page', 'total',
            ]);

        $this->assertSame(1, $response->json('total'));
        $this->assertSame(10, $response->json('per_page'));
        $this->assertSame('Vintage bicycle', $response->json('data.0.title'));
    }

    public function test_search_matches_the_description_too(): void
    {
        $this->makeAd(['title' => 'Unrelated heading', 'description' => 'Includes a bicycle frame']);

        $this->assertSame(1, $this->getJson('/api/soom/ads/search?title=bicycle')->json('total'));
    }

    public function test_search_matches_a_location_name(): void
    {
        $this->makeAds(2);

        $response = $this->getJson('/api/soom/ads/search?title='.urlencode($this->city()->name));

        $this->assertSame(2, $response->json('total'));
    }

    public function test_search_by_category_walks_the_category_tree(): void
    {
        $parent = $this->category(null, 'vehicles');
        $child = $this->category($parent->id, 'motorbikes');

        $this->makeAd(['category_id' => $parent->id]);
        $this->makeAd(['category_id' => $child->id]);
        $this->makeAd();

        $this->assertSame(2, $this->getJson('/api/soom/ads/search?category=vehicles')->json('total'));
    }

    public function test_search_returns_the_empty_response_when_nothing_matches(): void
    {
        $this->makeAd(['title' => 'Office chair', 'description' => 'Ergonomic']);

        $response = $this->getJson('/api/soom/ads/search?title=zzzznonexistent');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }

    public function test_search_with_an_unknown_category_returns_nothing(): void
    {
        $this->makeAd();

        $this->assertSame([], $this->getJson('/api/soom/ads/search?category=zzzznonexistent')->json('data'));
    }

    public function test_search_without_filters_returns_every_ad(): void
    {
        $this->makeAds(3);

        $this->assertSame(3, $this->getJson('/api/soom/ads/search')->json('total'));
    }

    public function test_admin_search_paginates_at_twenty_and_reports_the_active_count(): void
    {
        $this->makeAds(3, ['title' => 'Vintage bicycle']);
        $this->makeAd(['title' => 'Vintage bicycle'])->delete();

        $response = $this->actingAs($this->adUser('admin'), 'sanctum')
            ->getJson('/api/admin/ads/search?title=bicycle');

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'active_ads',
                'data' => ['*' => $this->adResourceKeys()],
                'current_page', 'last_page', 'per_page', 'total',
            ]);

        $this->assertSame(20, $response->json('per_page'));
        $this->assertSame(3, $response->json('active_ads'), 'active_ads counts non-trashed matches only.');
        $this->assertSame(4, $response->json('total'), 'The admin listing itself includes trashed ads.');
    }

    public function test_admin_search_status_filter_narrows_the_result_set(): void
    {
        $admin = $this->adUser('admin');
        $this->makeAds(3, ['title' => 'Vintage bicycle']);
        $this->makeAd(['title' => 'Vintage bicycle'])->delete();

        $active = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/ads/search?title=bicycle&status=active');
        $inactive = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/ads/search?title=bicycle&status=inactive');

        $this->assertSame(3, $active->json('total'));
        $this->assertSame(1, $inactive->json('total'));
    }

    public function test_admin_search_is_admin_only(): void
    {
        $this->actingAs($this->adUser(), 'sanctum')
            ->getJson('/api/admin/ads/search?title=bicycle')
            ->assertForbidden();
    }
}
