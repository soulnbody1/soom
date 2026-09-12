<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\AttributeCategoryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class CatalogCharacterizationTest extends CatalogTestCase
{
    use RefreshDatabase;

    public function test_category_index_returns_roots_with_nested_children(): void
    {
        $root = $this->category(null, 'root', 1);
        $child = $this->category($root->id, 'child');
        $grandchild = $this->category($child->id, 'grandchild');

        $response = $this->getJson('/api/soom/categories')->assertOk();

        $this->assertSame(
            [...$this->categoryResourceKeys(), 'children'],
            array_keys($response->json('data.0'))
        );
        $this->assertSame($root->id, $response->json('data.0.id'));
        $this->assertSame($child->id, $response->json('data.0.children.0.id'));
        $this->assertSame($grandchild->id, $response->json('data.0.children.0.children.0.id'));
    }

    public function test_category_index_parent_flag_omits_children(): void
    {
        $root = $this->category();
        $this->category($root->id);

        $response = $this->getJson('/api/soom/categories?parent=1')->assertOk();

        $this->assertSame($this->categoryResourceKeys(), array_keys($response->json('data.0')));
    }

    public function test_category_index_orders_by_display_order(): void
    {
        $second = $this->category(null, 'second', 2);
        $first = $this->category(null, 'first', 1);

        $response = $this->getJson('/api/soom/categories')->assertOk();

        $this->assertSame([$first->id, $second->id], array_column($response->json('data'), 'id'));
    }

    public function test_category_show_returns_the_category_with_children(): void
    {
        $root = $this->category();
        $child = $this->category($root->id);

        $response = $this->getJson('/api/soom/categories/'.$root->id)->assertOk();

        $this->assertSame($root->id, $response->json('data.id'));
        $this->assertSame([$child->id], array_column($response->json('data.children'), 'id'));
    }

    public function test_attributes_by_category_returns_the_success_envelope(): void
    {
        $category = $this->category();
        $attribute = $this->attribute();
        $this->optionsFor($attribute, ['a', 'b']);
        $this->attachAttribute($attribute, $category);

        $response = $this->getJson('/api/soom/attributes/by-category?category_id='.$category->id)->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame($this->attributeResourceKeys(), array_keys($response->json('data.0')));
        $this->assertSame(
            ['id', 'label', 'value', 'parent_option_id'],
            array_keys($response->json('data.0.options.0'))
        );
    }

    public function test_attributes_by_category_inherits_from_every_ancestor(): void
    {
        [$root, $middle, $leaf] = $this->categoryChain(3)->all();

        $rootAttribute = $this->attribute();
        $middleAttribute = $this->attribute();
        $leafAttribute = $this->attribute();

        $this->attachAttribute($rootAttribute, $root);
        $this->attachAttribute($middleAttribute, $middle);
        $this->attachAttribute($leafAttribute, $leaf);

        $response = $this->getJson('/api/soom/attributes/by-category?category_id='.$leaf->id)->assertOk();

        $this->assertEqualsCanonicalizing(
            [$rootAttribute->id, $middleAttribute->id, $leafAttribute->id],
            array_column($response->json('data'), 'id')
        );
    }

    public function test_attributes_by_category_skips_non_inheritable_ancestors(): void
    {
        [$parent, $child] = $this->categoryChain(2)->all();

        $inheritable = $this->attribute();
        $private = $this->attribute();

        $this->attachAttribute($inheritable, $parent, true);
        $this->attachAttribute($private, $parent, false);

        $response = $this->getJson('/api/soom/attributes/by-category?category_id='.$child->id)->assertOk();

        $this->assertSame([$inheritable->id], array_column($response->json('data'), 'id'));
    }

    public function test_attributes_by_category_drops_excluded_attributes(): void
    {
        [$parent, $child] = $this->categoryChain(2)->all();

        $attribute = $this->attribute();
        $this->attachAttribute($attribute, $parent);
        $this->excludeAttribute($attribute, $child);

        $response = $this->getJson('/api/soom/attributes/by-category?category_id='.$child->id)->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_attributes_by_category_expands_a_between_attribute_into_a_range(): void
    {
        $category = $this->category();
        $attribute = $this->attribute(['type' => 'between']);
        $this->optionsFor($attribute, ['2', '5']);
        $this->attachAttribute($attribute, $category);

        $response = $this->getJson('/api/soom/attributes/by-category?category_id='.$category->id)->assertOk();

        $this->assertSame(
            ['parent_option_id', 'values'],
            array_keys($response->json('data.0.options.0'))
        );
        $this->assertSame([2, 3, 4, 5], $response->json('data.0.options.0.values'));
    }

    public function test_attributes_by_category_requires_an_existing_category(): void
    {
        $this->getJson('/api/soom/attributes/by-category')->assertStatus(422);
        $this->getJson('/api/soom/attributes/by-category?category_id=999999')->assertStatus(422);
    }

    public function test_admin_can_create_update_and_delete_a_category(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/categories', ['name' => 'phones', 'display_order' => 3])
            ->assertCreated();

        $this->assertSame($this->categoryResourceKeys(), array_keys($created->json('data')));
        $id = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/categories/'.$id, ['name' => 'mobiles'])
            ->assertOk()
            ->assertJsonPath('data.name', 'mobiles');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/categories/'.$id)
            ->assertOk()
            ->assertJsonPath('message', 'Category deleted.');

        $this->assertDatabaseMissing('categories', ['id' => $id]);
    }

    public function test_category_writes_require_an_admin(): void
    {
        $this->postJson('/api/admin/categories', ['name' => 'x'])->assertStatus(401);

        $this->actingAs($this->catalogUser(), 'sanctum')
            ->postJson('/api/admin/categories', ['name' => 'x'])
            ->assertStatus(403);
    }

    public function test_admin_attribute_store_syncs_categories_and_returns_them(): void
    {
        $category = $this->category();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/attributes', [
                'name' => 'colour',
                'type' => 'select',
                'categories' => [['id' => $category->id, 'is_inheritable' => true]],
            ])
            ->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame(
            [...$this->attributeResourceKeys(), 'categories'],
            array_keys($response->json('data'))
        );
        $this->assertSame(
            ['id', 'name', 'is_inheritable'],
            array_keys($response->json('data.categories.0'))
        );
        $this->assertDatabaseHas('attribute_category', [
            'attribute_id' => $response->json('data.id'),
            'category_id' => $category->id,
            'is_inheritable' => true,
        ]);
    }

    public function test_admin_attribute_update_replaces_the_category_set(): void
    {
        $attribute = $this->attribute();
        $kept = $this->category();
        $dropped = $this->category();

        $this->attachAttribute($attribute, $dropped);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/attributes/'.$attribute->id, [
                'name' => 'renamed',
                'categories' => [['id' => $kept->id, 'is_inheritable' => false]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('attribute_category', [
            'attribute_id' => $attribute->id,
            'category_id' => $kept->id,
        ]);
        $this->assertDatabaseMissing('attribute_category', [
            'attribute_id' => $attribute->id,
            'category_id' => $dropped->id,
        ]);
    }

    public function test_admin_attribute_destroy_cascades_options_and_pivots(): void
    {
        $category = $this->category();
        $attribute = $this->attribute();
        $this->optionsFor($attribute, ['a']);
        $this->attachAttribute($attribute, $category);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/admin/attributes/'.$attribute->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('attributes', ['id' => $attribute->id]);
        $this->assertDatabaseMissing('attribute_options', ['attribute_id' => $attribute->id]);
        $this->assertDatabaseMissing('attribute_category', ['attribute_id' => $attribute->id]);
    }

    public function test_admin_options_by_attribute_returns_the_parent_name(): void
    {
        $attribute = $this->attribute(['name' => 'colour']);
        $this->optionsFor($attribute, ['red']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/attributes/'.$attribute->id)
            ->assertOk();

        $this->assertSame('colour', $response->json('parent-name'));
        $this->assertSame(['red'], array_column($response->json('data'), 'value'));
    }

    public function test_admin_sync_attributes_to_category_replaces_the_attribute_set(): void
    {
        $category = $this->category();
        $kept = $this->attribute();
        $dropped = $this->attribute();

        $this->attachAttribute($dropped, $category);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/attributes/sync-attributes', [
                'category_id' => $category->id,
                'attributes' => [['id' => $kept->id, 'is_inheritable' => true]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame([$kept->id], $category->attributes()->pluck('attributes.id')->all());
    }

    public function test_admin_option_crud_enforces_the_between_pair_limit(): void
    {
        $admin = $this->admin();
        $attribute = $this->attribute(['type' => 'between']);

        foreach (['1', '9'] as $value) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/admin/attribute-options', [
                    'attribute_id' => $attribute->id,
                    'value' => $value,
                    'label' => $value,
                ])
                ->assertOk();
        }

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/attribute-options', [
                'attribute_id' => $attribute->id,
                'value' => '10',
                'label' => '10',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_admin_option_update_and_destroy(): void
    {
        $admin = $this->admin();
        $attribute = $this->attribute();
        $option = $this->optionsFor($attribute, ['red'])->first();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/attribute-options/'.$option->id, ['label' => 'crimson'])
            ->assertOk()
            ->assertJsonPath('data.label', 'crimson');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/attribute-options/'.$option->id)
            ->assertOk();

        $this->assertDatabaseMissing('attribute_options', ['id' => $option->id]);
    }

    public function test_exclude_records_an_exception_for_an_inherited_attribute(): void
    {
        [$parent, $child] = $this->categoryChain(2)->all();
        $attribute = $this->attribute();
        $this->attachAttribute($attribute, $parent);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/attributes/exclude', [
                'attribute_id' => $attribute->id,
                'category_id' => $child->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('attribute_category_exceptions', [
            'attribute_id' => $attribute->id,
            'category_id' => $child->id,
        ]);
    }

    public function test_include_clears_the_exception(): void
    {
        [$parent, $child] = $this->categoryChain(2)->all();
        $attribute = $this->attribute();
        $this->attachAttribute($attribute, $parent);
        $this->excludeAttribute($attribute, $child);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/attributes/include', [
                'attribute_id' => $attribute->id,
                'category_id' => $child->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, AttributeCategoryException::count());
    }

    public function test_attribute_writes_require_an_admin(): void
    {
        $this->postJson('/api/admin/attributes', ['name' => 'x', 'type' => 'text'])->assertStatus(401);

        $this->actingAs($this->catalogUser(), 'sanctum')
            ->postJson('/api/admin/attributes', ['name' => 'x', 'type' => 'text'])
            ->assertStatus(403);
    }
}
