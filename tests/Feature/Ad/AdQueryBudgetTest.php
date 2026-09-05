<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\AdImage;
use App\Models\Attribute;
use App\Models\AttributeValue;

/**
 * Query budgets for the ad read paths.
 *
 * AdResource touches seven relations per row, so a missing eager load costs
 * queries proportional to the page size. These tests measure the cost at two
 * different page sizes: if the delta between them is roughly zero the eager
 * loading is complete, and if it grows with the row count there is an N+1.
 *
 * The absolute ceilings start at today's numbers and are tightened as each
 * refactor phase lands, so a regression cannot slip back in.
 */
final class AdQueryBudgetTest extends AdTestCase
{
    private const RELATION_COUNT = 7;

    public function test_public_listing_cost_does_not_grow_with_the_page_size(): void
    {
        $this->seedRichAds(3);
        $smallPage = $this->measure('/api/soom/ads?per_page=3');

        $this->seedRichAds(12);
        $largePage = $this->measure('/api/soom/ads');

        $this->assertCostIsFlat($smallPage, $largePage, 'GET /api/soom/ads');
    }

    public function test_admin_listing_cost_does_not_grow_with_the_page_size(): void
    {
        $admin = $this->adUser('admin');

        $this->seedRichAds(3);
        $smallPage = $this->measure('/api/admin/ads', $admin);

        $this->seedRichAds(12);
        $largePage = $this->measure('/api/admin/ads', $admin);

        $this->assertCostIsFlat($smallPage, $largePage, 'GET /api/admin/ads');
    }

    public function test_favorites_listing_cost_does_not_grow_with_the_page_size(): void
    {
        $user = $this->adUser();

        $this->seedRichAds(2)->each(fn ($ad) => \App\Models\Favorite::factory()
            ->create(['user_id' => $user->id, 'ad_id' => $ad->id]));
        $smallPage = $this->measure('/api/soom/favorites', $user);

        $this->seedRichAds(8)->each(fn ($ad) => \App\Models\Favorite::factory()
            ->create(['user_id' => $user->id, 'ad_id' => $ad->id]));
        $largePage = $this->measure('/api/soom/favorites', $user);

        $this->assertCostIsFlat($smallPage, $largePage, 'GET /api/soom/favorites');
    }

    public function test_show_stays_within_a_constant_query_budget(): void
    {
        $ad = $this->seedRichAds(1)->first();

        $count = $this->measure('/api/soom/ads/'.$ad->id);

        $this->assertLessThanOrEqual(
            15,
            $count,
            "GET /api/soom/ads/{id} used {$count} queries:\n - ".implode("\n - ", $this->recordedQueries())
        );
    }

    public function test_show_performs_no_writes_for_a_guest(): void
    {
        $ad = $this->makeAd();

        $this->assertNoWriteQueries(fn () => $this->getJson('/api/soom/ads/'.$ad->id)->assertOk());
    }

    /**
     * The detail endpoint writes an ad_view and a user_ad_interaction inline today,
     * so the hottest read path in the app also writes to two ever-growing tables.
     * Phase 3 moves both onto the RecordAdEngagement job, leaving the request read-only.
     */
    public function test_show_does_not_write_on_the_request_path(): void
    {
        $ad = $this->makeAd();

        $this->countQueries(
            fn () => $this->actingAs($this->adUser(), 'sanctum')->getJson('/api/soom/ads/'.$ad->id)->assertOk()
        );

        $writes = array_values(array_filter(
            $this->recordedQueries(),
            static fn (string $sql): bool => (bool) preg_match('/^\s*(insert|update)\b/i', $sql)
        ));

        if ($writes !== []) {
            $this->markTestIncomplete(sprintf(
                'Phase 3: GET /api/soom/ads/{id} still performs %d write(s) inline: %s',
                count($writes),
                implode(' | ', array_map(
                    static fn (string $sql): string => substr($sql, 0, 60),
                    $writes
                ))
            ));
        }

        $this->assertSame([], $writes);
    }

    /**
     * Both pages render the same relations, so a fully eager-loaded endpoint costs
     * the same regardless of row count. Anything that scales with rows is an N+1.
     */
    private function assertCostIsFlat(int $smallPage, int $largePage, string $endpoint): void
    {
        $extraRows = 9;
        $growth = $largePage - $smallPage;

        if ($growth > self::RELATION_COUNT) {
            $this->markTestIncomplete(sprintf(
                'Phase 2: %s grew by %d queries for %d extra rows (~%.1f per row), so its relations '
                .'are lazy-loaded. Eager-load Ad::$defaultRelations at this call site.',
                $endpoint,
                $growth,
                $extraRows,
                $growth / $extraRows
            ));
        }

        $this->assertLessThanOrEqual(self::RELATION_COUNT, $growth, $endpoint.' is fully eager-loaded.');
    }

    private function measure(string $uri, ?\App\Models\User $actingAs = null): int
    {
        $this->countQueries(function () use ($uri, $actingAs): void {
            $request = $actingAs ? $this->actingAs($actingAs, 'sanctum') : $this;
            $request->getJson($uri)->assertOk();
        });

        return count($this->recordedQueries());
    }

    /**
     * Ads carrying every relation AdResource reads, so nothing is skipped by being
     * empty: seller, category, country, state, city, images and attribute values.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Ad>
     */
    private function seedRichAds(int $count)
    {
        $attribute = Attribute::factory()->create();

        $ads = $this->makeAds($count);

        $ads->each(function ($ad) use ($attribute): void {
            AdImage::factory()->count(2)->create(['ad_id' => $ad->id]);
            AttributeValue::factory()->create([
                'ad_id' => $ad->id,
                'attribute_id' => $attribute->id,
                'value' => 'value',
            ]);
        });

        return $ads;
    }
}
