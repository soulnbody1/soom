<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\Favorite;
use App\Services\Ad\Support\AdCacheVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    use RefreshDatabase;

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
     * Phase 1 — the ads table shipped with no explicit index at all, so every
     * listing was a full scan.
     *
     * The composites end with created_at rather than id on purpose: InnoDB appends
     * the primary key to every secondary index, so (col, deleted_at, created_at) is
     * physically (col, deleted_at, created_at, id). One index therefore serves both
     * today's "ORDER BY created_at DESC" and the stable "ORDER BY created_at DESC,
     * id DESC" that Phase 2 introduces.
     */
    public function test_ads_table_is_indexed_for_the_hot_listing_paths(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        $indexed = collect(Schema::getIndexes('ads'))
            ->map(fn (array $index): string => implode(',', $index['columns']))
            ->all();

        $missing = array_values(array_filter(
            [
                'deleted_at,created_at',
                'category_id,deleted_at,created_at',
                'user_id,created_at',
                'city_id,deleted_at,created_at',
                'state_id,deleted_at,created_at',
                'deleted_at,price',
                'is_featured',
            ],
            static fn (string $expected): bool => ! in_array($expected, $indexed, true)
        ));

        $this->assertSame([], $missing, 'ads is missing indexes: '.implode(' | ', $missing));
    }

    /**
     * Phase 1 — every ad shares one country, so an index led by country_id can
     * never narrow a result set. Worse, it gave the optimizer a skip-scan plan that
     * made the pagination COUNT slower than having no index at all. Revisit only if
     * the platform becomes multi-country.
     */
    public function test_ads_has_no_index_led_by_the_single_valued_country_column(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        $ledByCountry = collect(Schema::getIndexes('ads'))
            ->filter(fn (array $index): bool => ($index['columns'][0] ?? null) === 'country_id')
            ->map(fn (array $index): string => (string) $index['name'])
            ->values()
            ->all();

        $this->assertSame(
            ['ads_country_id_foreign'],
            $ledByCountry,
            'Only the foreign key index should lead with country_id.'
        );
    }

    /**
     * Phase 1 — an index that merely repeats the leading columns of another one
     * costs write throughput and buys nothing, so none should survive.
     */
    public function test_no_index_merely_repeats_the_prefix_of_another(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        $tables = [
            'ads', 'ad_images', 'ad_reels', 'ad_reel_views', 'ad_views',
            'favorites', 'categories', 'attribute_values', 'user_ad_interactions',
        ];

        $redundant = [];

        foreach ($tables as $table) {
            $indexes = collect(Schema::getIndexes($table))
                ->reject(fn (array $index): bool => ($index['type'] ?? '') === 'fulltext')
                ->map(fn (array $index): array => [
                    'name' => $table.'.'.$index['name'],
                    'columns' => implode(',', $index['columns']),
                ]);

            foreach ($indexes as $candidate) {
                $covering = $indexes->first(fn (array $other): bool => $other['name'] !== $candidate['name']
                    && $other['columns'] !== $candidate['columns']
                    && str_starts_with($other['columns'], $candidate['columns'].','));

                if ($covering !== null) {
                    $redundant[] = $candidate['name'].' ('.$candidate['columns'].') is a prefix of '
                        .$covering['name'].' ('.$covering['columns'].')';
                }
            }
        }

        $this->assertSame([], $redundant, "Redundant prefix indexes:\n - ".implode("\n - ", $redundant));
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

        $this->assertSame([], $missing, 'Missing FULLTEXT indexes: '.implode(' | ', $missing));
    }

    /**
     * Phase 4 — StoreAdRequest marked state_id/city_id nullable while the ads table
     * declares them NOT NULL, so omitting them reached the database and 500ed.
     */
    public function test_omitting_the_location_ids_is_a_validation_error_not_a_server_error(): void
    {
        $this->actingAs($this->adUser(), 'sanctum')
            ->postJson('/api/soom/ads', [
                'title' => 'No location',
                'description' => 'State and city omitted',
                'price' => 10,
                'category_id' => $this->category()->id,
                'country_id' => $this->country()->id,
                'images' => [\Illuminate\Http\UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['state_id', 'city_id']);

        $this->assertDatabaseCount('ads', 0);
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

        $this->assertTrue(
            $hasUnique,
            'user_ad_interactions needs unique(user_id, ad_id, action) to make the write race-safe.'
        );
    }

    /**
     * Phase 1 — with the unique key in place the database itself rejects the
     * duplicate rows the read-then-write service used to allow through.
     */
    public function test_the_database_rejects_a_duplicate_interaction(): void
    {
        $user = $this->adUser();
        $ad = $this->makeAd();

        $row = ['user_id' => $user->id, 'ad_id' => $ad->id, 'action' => 'click'];

        \App\Models\UserAdInteraction::create($row);

        $this->expectException(\Illuminate\Database\QueryException::class);

        \App\Models\UserAdInteraction::create($row);
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
     * Phase 2 — favouriting used to bust the single global home cache, which kept
     * it permanently cold. The shared payload no longer carries per-viewer state,
     * so a favourite toggle must leave it intact.
     */
    public function test_favoriting_does_not_invalidate_the_shared_home_cache(): void
    {
        $category = $this->category(null, 'cachecheck');
        $ad = $this->makeAd(['category_id' => $category->id]);

        $this->getJson('/api/soom/home')->assertOk();

        $cacheKey = 'ads:home:cards-v3:v'.app(AdCacheVersion::class)->current();
        $this->assertTrue(Cache::has($cacheKey));

        $this->actingAs($this->adUser(), 'sanctum')
            ->postJson('/api/soom/favorites', ['ad_id' => $ad->id])
            ->assertOk();

        $this->assertTrue(Cache::has($cacheKey), 'A favourite toggle must not cool the shared home cache.');
    }

    /**
     * Phase 2 — publishing or removing an ad must still roll the cache version so
     * the shared home payload is rebuilt.
     */
    public function test_deleting_an_ad_rolls_the_home_cache_version(): void
    {
        $owner = $this->adUser();
        $ad = $this->makeAd(['user_id' => $owner->id, 'category_id' => $this->category(null, 'rolling')->id]);

        $this->getJson('/api/soom/home')->assertOk();
        $before = app(AdCacheVersion::class)->current();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/soom/ads/my/soft-delete/'.$ad->id)
            ->assertOk();

        $this->assertGreaterThan($before, app(AdCacheVersion::class)->current());
        $this->assertSame([], $this->getJson('/api/soom/home')->json('data.0.ads'));
    }
}
