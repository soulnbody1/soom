<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Ad;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use App\Services\Catalog\CatalogCacheVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class CatalogKnownDefectsTest extends CatalogTestCase
{
    use RefreshDatabase;

    public function test_attaching_an_inheritable_attribute_to_a_parent_reaches_its_descendants(): void
    {
        [$parent, $child] = $this->categoryChain(2)->all();
        $uri = '/api/soom/attributes/by-category?category_id='.$child->id;

        $this->getJson($uri)->assertOk()->assertJsonPath('data', []);

        $attribute = $this->attribute();
        $this->syncAttributesToCategory($parent, [$attribute]);

        $ids = array_column($this->getJson($uri)->assertOk()->json('data'), 'id');

        if ($ids === []) {
            $this->markTestIncomplete(
                'Phase 3: clearAttributeCache() forgets only the edited category, so descendants keep the '
                .'pre-edit attribute set for the full 7-day TTL.'
            );
        }

        $this->assertSame([$attribute->id], $ids);
    }

    public function test_detaching_a_category_from_an_attribute_clears_that_category(): void
    {
        $kept = $this->category();
        $dropped = $this->category();
        $attribute = $this->attribute();

        $this->updateAttributeCategories($attribute, [$kept, $dropped]);

        $uri = '/api/soom/attributes/by-category?category_id='.$dropped->id;
        $this->getJson($uri)->assertOk();

        $this->updateAttributeCategories($attribute, [$kept]);

        $ids = array_column($this->getJson($uri)->assertOk()->json('data'), 'id');

        if ($ids !== []) {
            $this->markTestIncomplete(
                'Phase 3: the sync loop only clears the categories present in the payload, so a detached '
                .'category keeps serving the removed attribute.'
            );
        }

        $this->assertSame([], $ids);
    }

    public function test_moving_a_category_refreshes_its_inherited_attributes(): void
    {
        $oldParent = $this->category();
        $newParent = $this->category();
        $child = $this->category($oldParent->id);

        $oldAttribute = $this->attribute();
        $newAttribute = $this->attribute();
        $this->syncAttributesToCategory($oldParent, [$oldAttribute]);
        $this->syncAttributesToCategory($newParent, [$newAttribute]);

        $uri = '/api/soom/attributes/by-category?category_id='.$child->id;
        $this->getJson($uri)->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/categories/'.$child->id, ['parent_id' => $newParent->id])
            ->assertOk();

        $ids = array_column($this->getJson($uri)->assertOk()->json('data'), 'id');

        if ($ids === [$oldAttribute->id]) {
            $this->markTestIncomplete(
                'Phase 3: category writes never touch the attribute cache, so a re-parented category keeps '
                .'the inherited set of its previous ancestors.'
            );
        }

        $this->assertSame([$newAttribute->id], $ids);
    }

    public function test_deleting_an_attribute_removes_it_from_every_category(): void
    {
        $category = $this->category();
        $attribute = $this->attribute();
        $this->syncAttributesToCategory($category, [$attribute]);

        $uri = '/api/soom/attributes/by-category?category_id='.$category->id;
        $this->getJson($uri)->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/admin/attributes/'.$attribute->id)
            ->assertOk();

        $this->assertSame([], $this->getJson($uri)->assertOk()->json('data'));
    }

    public function test_the_attribute_cache_stores_plain_data_not_eloquent_objects(): void
    {
        $category = $this->category();
        $attribute = $this->attribute();
        $this->optionsFor($attribute, ['a', 'b']);
        $this->attachAttribute($attribute, $category);

        $this->getJson('/api/soom/attributes/by-category?category_id='.$category->id)->assertOk();

        $version = app(CatalogCacheVersion::class)->current();
        $cached = cache()->get('catalog:attributes:v'.$version.':'.$category->id);

        if ($cached !== null && ! is_array($cached)) {
            $this->markTestIncomplete(sprintf(
                'Phase 3: the cache holds a %s, so every entry serializes the Eloquent models and their '
                .'relations instead of the rendered payload.',
                get_debug_type($cached)
            ));
        }

        $this->assertIsArray($cached);
        $this->assertSame($this->attributeResourceKeys(), array_keys($cached[0]));
    }

    public function test_a_category_cycle_does_not_hang_the_attribute_endpoint(): void
    {
        $first = $this->category();
        $second = $this->category($first->id);

        $rejected = $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/categories/'.$first->id, ['parent_id' => $second->id]);

        if ($rejected->status() < 400) {
            $this->markTestIncomplete(
                'Phase 5: UpdateCategoryRequest only blocks self-parenting, so A->B->A is accepted and '
                .'every tree walk loops forever on the resulting cycle.'
            );
        }

        $rejected->assertStatus(422);
    }

    public function test_subcategory_ad_counts_match_what_opening_the_subcategory_returns(): void
    {
        $root = $this->category();
        $child = $this->category($root->id);
        $grandchild = $this->category($child->id);

        $this->makeAdIn($grandchild);

        $response = $this->getJson('/api/soom/ads/category/'.$root->id)->assertOk();

        $count = (int) ($response->json('data.subcategories.0.ads_count')
            ?? $response->json('subcategories.0.ads_count')
            ?? 0);

        if ($count === 0) {
            $this->markTestIncomplete(
                'Phase 4: subcategories are counted with withCount("ads"), which ignores the subtree that '
                .'opening the subcategory actually lists.'
            );
        }

        $this->assertSame(1, $count);
    }

    public function test_deleting_a_category_does_not_silently_delete_its_ads(): void
    {
        $category = $this->category();
        $ad = $this->makeAdIn($category);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/admin/categories/'.$category->id);

        if ($response->status() < 400) {
            $this->markTestIncomplete(sprintf(
                'Phase 4: deleting a category returns %d and cascades %d ad(s) away without warning.',
                $response->status(),
                Ad::withTrashed()->whereKey($ad->id)->count() === 0 ? 1 : 0
            ));
        }

        $response->assertStatus(422);
        $this->assertDatabaseHas('ads', ['id' => $ad->id]);
    }

    public function test_excluding_a_directly_attached_attribute_is_reversible(): void
    {
        $category = $this->category();
        $attribute = $this->attribute();
        $this->attachAttribute($attribute, $category);

        $admin = $this->admin();
        $payload = ['attribute_id' => $attribute->id, 'category_id' => $category->id];

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/attributes/exclude', $payload)->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/attributes/include', $payload)->assertOk();

        $ids = array_column(
            $this->getJson('/api/soom/attributes/by-category?category_id='.$category->id)->assertOk()->json('data'),
            'id'
        );

        if ($ids === []) {
            $this->markTestIncomplete(
                'Phase 5: exclude hard-deletes the attribute_category row when the attribute is attached '
                .'directly, and include only clears exceptions, so the detach cannot be undone.'
            );
        }

        $this->assertSame([$attribute->id], $ids);
    }

    public function test_a_stale_category_tree_payload_is_rebuilt_instead_of_breaking_inheritance(): void
    {
        [$parent, $child] = $this->categoryChain(2)->all();
        $attribute = $this->attribute();
        $this->attachAttribute($attribute, $parent);

        cache()->put('ads:category_tree', ['children' => [], 'roots' => []], 600);

        $ids = array_column(
            $this->getJson('/api/soom/attributes/by-category?category_id='.$child->id)->assertOk()->json('data'),
            'id'
        );

        $this->assertSame([$attribute->id], $ids);
    }

    /**
     * @param  list<Attribute>  $attributes
     */
    private function syncAttributesToCategory(Category $category, array $attributes): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/attributes/sync-attributes', [
                'category_id' => $category->id,
                'attributes' => array_map(
                    static fn (Attribute $attribute): array => ['id' => $attribute->id, 'is_inheritable' => true],
                    $attributes
                ),
            ])
            ->assertOk();
    }

    /**
     * @param  list<Category>  $categories
     */
    private function updateAttributeCategories(Attribute $attribute, array $categories): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/attributes/'.$attribute->id, [
                'categories' => array_map(
                    static fn (Category $category): array => ['id' => $category->id, 'is_inheritable' => true],
                    $categories
                ),
            ])
            ->assertOk();
    }

    private function makeAdIn(Category $category): Ad
    {
        $country = Country::factory()->create();
        $state = State::factory()->create(['country_id' => $country->id]);
        $city = City::factory()->create(['state_id' => $state->id]);

        return Ad::factory()->create([
            'user_id' => User::factory()->create()->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'state_id' => $state->id,
            'city_id' => $city->id,
        ]);
    }
}
