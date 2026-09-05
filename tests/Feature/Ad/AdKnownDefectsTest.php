<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\Favorite;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmed defects in the ad module, each pinned by a test.
 *
 * Unlike the rest of the suite these assert the INTENDED behaviour, so they are
 * expected to fail until the corresponding refactor phase lands. Every one is
 * marked incomplete with the phase that fixes it, so the suite stays green while
 * still reporting the outstanding work.
 */
final class AdKnownDefectsTest extends AdTestCase
{
    /**
     * Phase 2 — the home payload is computed per user (withIsFavorite) but cached
     * under the global key "home_ads_data", so whoever warms the cache decides
     * every other viewer's is_favorite flags for the next ten minutes.
     */
    public function test_home_does_not_leak_one_users_favorites_to_another(): void
    {
        $category = $this->category(null, 'leaky');
        $ad = $this->makeAd(['category_id' => $category->id]);

        $owner = $this->adUser();
        $stranger = $this->adUser();
        Favorite::factory()->create(['user_id' => $owner->id, 'ad_id' => $ad->id]);

        // The owner warms the cache; their flags must not follow the next viewer.
        $ownerRow = $this->actingAs($owner, 'sanctum')->getJson('/api/soom/home')->json('data.0.ads.0');
        $strangerRow = $this->actingAs($stranger, 'sanctum')->getJson('/api/soom/home')->json('data.0.ads.0');

        $this->assertTrue((bool) $ownerRow['is_favorite']);

        if ((bool) $strangerRow['is_favorite'] === true) {
            $this->markTestIncomplete(
                'Phase 2: home_ads_data caches a per-user payload under a global key, '
                .'so is_favorite leaks between users.'
            );
        }

        $this->assertFalse((bool) $strangerRow['is_favorite']);
    }

    /**
     * Phase 1 — the ads table has no explicit index at all, so every listing scans.
     */
    public function test_ads_table_is_indexed_for_the_hot_listing_paths(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        $indexed = collect(Schema::getIndexes('ads'))
            ->flatMap(fn (array $index): array => [implode(',', $index['columns'])])
            ->all();

        $missing = array_values(array_filter(
            ['category_id,deleted_at,id', 'deleted_at,id', 'user_id,deleted_at,id', 'price'],
            static fn (string $expected): bool => ! in_array($expected, $indexed, true)
        ));

        if ($missing !== []) {
            $this->markTestIncomplete('Phase 1: ads is missing indexes: '.implode(' | ', $missing));
        }

        $this->assertSame([], $missing);
    }

    /**
     * Phase 1 — three call sites issue MATCH ... AGAINST but no migration creates a
     * FULLTEXT index, so a freshly migrated MySQL database raises error 1191.
     */
    public function test_fulltext_indexes_exist_for_every_match_against_call_site(): void
    {
        $this->requireMysql('FULLTEXT indexes only exist on MySQL');

        $missing = [];

        foreach (['ads' => 'title,description', 'categories' => 'name', 'users' => 'name,phone'] as $table => $columns) {
            $hasFulltext = collect(Schema::getIndexes($table))
                ->contains(fn (array $index): bool => implode(',', $index['columns']) === $columns
                    && in_array('fulltext', array_map('strtolower', (array) ($index['type'] ?? [])), true));

            if (! $hasFulltext) {
                $missing[] = $table.'('.$columns.')';
            }
        }

        if ($missing !== []) {
            $this->markTestIncomplete('Phase 1: missing FULLTEXT indexes: '.implode(' | ', $missing));
        }

        $this->assertSame([], $missing);
    }

    /**
     * Phase 4 — StoreAdRequest marks state_id/city_id nullable while the ads table
     * declares them NOT NULL, so omitting them is a 500 rather than a 422.
     */
    public function test_omitting_the_optional_location_ids_is_a_validation_error_not_a_server_error(): void
    {
        $response = $this->actingAs($this->adUser(), 'sanctum')->postJson('/api/soom/ads', [
            'title' => 'No location',
            'description' => 'State and city omitted',
            'price' => 10,
            'category_id' => $this->category()->id,
            'country_id' => $this->country()->id,
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('a.jpg')],
        ]);

        if ($response->status() === 500) {
            $this->markTestIncomplete(
                'Phase 4: state_id/city_id are validated as nullable but the schema is NOT NULL, '
                .'so the request 500s instead of returning 422.'
            );
        }

        $response->assertStatus(422);
    }

    /**
     * Phase 1 — user_ad_interactions has no unique key, and the service does a
     * read-then-write, so concurrent taps insert duplicate rows. Those duplicates
     * inflate the "COUNT(*) >= 3" audience threshold in SendAdNotification.
     */
    public function test_user_ad_interactions_are_unique_per_user_ad_and_action(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        $hasUnique = collect(Schema::getIndexes('user_ad_interactions'))
            ->contains(fn (array $index): bool => ($index['unique'] ?? false)
                && implode(',', $index['columns']) === 'user_id,ad_id,action');

        if (! $hasUnique) {
            $this->markTestIncomplete(
                'Phase 1: user_ad_interactions needs unique(user_id, ad_id, action) '
                .'to make the interaction write race-safe.'
            );
        }

        $this->assertTrue($hasUnique);
    }

    /**
     * Phase 1/2 — getNearbyAds orders with the MySQL-only FIELD() function, so the
     * category endpoint is a hard 500 on any other driver.
     */
    public function test_nearby_ads_do_not_depend_on_mysql_only_sql(): void
    {
        if (config('database.default') === 'mysql') {
            $this->markTestSkipped('The portability defect only shows on non-MySQL drivers.');
        }

        $category = $this->category();
        $this->makeAds(2, ['category_id' => $category->id]);

        $response = $this->actingAs($this->adUser(), 'sanctum')
            ->getJson('/api/soom/ads/category/'.$category->id);

        if ($response->status() === 500) {
            $this->markTestIncomplete(
                'Phase 2: getNearbyAds uses orderByRaw("FIELD(city_id, ?)"), which only exists in MySQL.'
            );
        }

        $response->assertOk();
    }

    /**
     * Phase 2 — favouriting any ad by any user busts the single global home cache,
     * so at scale the cache is never warm and every request rebuilds the feed.
     */
    public function test_favoriting_does_not_invalidate_the_shared_home_cache(): void
    {
        $category = $this->category(null, 'cachecheck');
        $ad = $this->makeAd(['category_id' => $category->id]);

        $this->getJson('/api/soom/home')->assertOk();

        $this->actingAs($this->adUser(), 'sanctum')
            ->postJson('/api/soom/favorites', ['ad_id' => $ad->id])
            ->assertOk();

        $stillCached = cache()->has('home_ads_data');

        if (! $stillCached) {
            $this->markTestIncomplete(
                'Phase 2: FavoriteController forgets home_ads_data on every toggle, '
                .'which keeps the shared home cache permanently cold.'
            );
        }

        $this->assertTrue($stillCached);
    }
}
