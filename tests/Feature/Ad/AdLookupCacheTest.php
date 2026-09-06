<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\City;
use App\Services\Ad\Support\CategoryTreeResolver;
use App\Services\Ad\Support\GeoNameResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class AdLookupCacheTest extends AdTestCase
{
    use RefreshDatabase;

    public function test_a_new_child_category_is_visible_to_the_tree_immediately(): void
    {
        $parent = $this->category();

        $this->assertSame([$parent->id], app(CategoryTreeResolver::class)->subtreeIds($parent->id));

        $child = $this->category($parent->id);

        $this->assertEqualsCanonicalizing(
            [$parent->id, $child->id],
            app(CategoryTreeResolver::class)->subtreeIds($parent->id)
        );
    }

    public function test_a_renamed_category_is_reflected_in_the_home_feed(): void
    {
        $category = $this->category(null, 'before');
        $this->makeAd(['category_id' => $category->id]);

        $this->assertSame('before', $this->getJson('/api/soom/home')->json('data.0.category'));

        $category->update(['name' => 'after']);

        $this->assertSame('after', $this->getJson('/api/soom/home')->json('data.0.category'));
    }

    public function test_a_new_city_is_searchable_immediately(): void
    {
        $resolver = app(GeoNameResolver::class);

        $this->assertSame([], $resolver->matchingIds('Springfield')['city_ids']);

        $city = City::factory()->create(['state_id' => $this->state()->id, 'name' => 'Springfield']);

        $this->assertSame([$city->id], app(GeoNameResolver::class)->matchingIds('Springfield')['city_ids']);
    }
}
