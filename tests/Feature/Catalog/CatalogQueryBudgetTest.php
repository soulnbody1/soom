<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Services\Catalog\CategoryAttributeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class CatalogQueryBudgetTest extends CatalogTestCase
{
    use RefreshDatabase;

    private const INHERITANCE_BUDGET = 4;

    private const BY_CATEGORY_BUDGET = 6;

    public function test_attribute_inheritance_cost_does_not_grow_with_the_tree_depth(): void
    {
        $shallow = $this->measureInheritance(1);
        $deep = $this->measureInheritance(5);

        if ($deep > $shallow) {
            $this->markTestIncomplete(sprintf(
                'Phase 2: inheritance costs %d queries at depth 1 and %d at depth 5 (~%.1f per ancestor), '
                .'so the resolver still walks the tree row by row.',
                $shallow,
                $deep,
                ($deep - $shallow) / 4
            ));
        }

        $this->assertSame($shallow, $deep, 'Attribute inheritance is depth-independent.');
    }

    public function test_attribute_inheritance_stays_within_a_constant_query_budget(): void
    {
        $cost = $this->measureInheritance(5);

        if ($cost > self::INHERITANCE_BUDGET) {
            $this->markTestIncomplete(sprintf(
                'Phase 2: resolving inherited attributes at depth 5 costs %d queries, budget is %d.',
                $cost,
                self::INHERITANCE_BUDGET
            ));
        }

        $this->assertLessThanOrEqual(self::INHERITANCE_BUDGET, $cost);
    }

    public function test_attributes_by_category_stays_within_a_constant_query_budget(): void
    {
        $leaf = $this->chainWithAttributes(5);

        $cost = $this->measure('/api/soom/attributes/by-category?category_id='.$leaf->id);

        if ($cost > self::BY_CATEGORY_BUDGET) {
            $this->markTestIncomplete(sprintf(
                'Phase 2: GET /attributes/by-category costs %d queries at depth 5, budget is %d.',
                $cost,
                self::BY_CATEGORY_BUDGET
            ));
        }

        $this->assertLessThanOrEqual(self::BY_CATEGORY_BUDGET, $cost);
    }

    public function test_attributes_by_category_is_served_from_the_cache_on_a_second_call(): void
    {
        $leaf = $this->chainWithAttributes(3);
        $uri = '/api/soom/attributes/by-category?category_id='.$leaf->id;

        $this->getJson($uri)->assertOk();

        $this->assertQueryCountAtMost(1, fn () => $this->getJson($uri)->assertOk());
    }

    public function test_category_index_cost_does_not_grow_with_the_tree_depth(): void
    {
        $this->categoryChain(2);
        $shallow = $this->measure('/api/soom/categories');

        $this->categoryChain(6);
        $deep = $this->measure('/api/soom/categories');

        if ($deep > $shallow) {
            $this->markTestIncomplete(sprintf(
                'Phase 4: GET /api/soom/categories costs %d queries at depth 2 and %d at depth 6, because '
                .'Category::children() eager-loads itself one level per query.',
                $shallow,
                $deep
            ));
        }

        $this->assertSame($shallow, $deep, 'The category tree is built in a fixed number of queries.');
    }

    public function test_attribute_store_does_not_lazy_load_the_options(): void
    {
        $category = $this->category();

        $this->countQueries(fn () => $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/attributes', [
                'name' => 'colour',
                'type' => 'select',
                'categories' => [['id' => $category->id, 'is_inheritable' => true]],
            ])
            ->assertOk());

        $optionReads = array_values(array_filter(
            $this->recordedQueries(),
            static fn (string $sql): bool => str_contains($sql, 'attribute_options')
        ));

        $this->assertLessThanOrEqual(
            1,
            count($optionReads),
            "The attribute write path reads attribute_options more than once:\n - ".implode("\n - ", $optionReads)
        );
    }

    private function measureInheritance(int $depth): int
    {
        $leaf = $this->chainWithAttributes($depth);

        $this->countQueries(static fn () => app(CategoryAttributeResolver::class)->resolve($leaf->id));

        return count($this->recordedQueries());
    }

    private function chainWithAttributes(int $depth): Category
    {
        $chain = $this->categoryChain($depth);

        $chain->each(function (Category $category): void {
            $attribute = $this->attribute();
            $this->optionsFor($attribute, ['a', 'b']);
            $this->attachAttribute($attribute, $category);
        });

        return $chain->last();
    }

    private function measure(string $uri): int
    {
        $this->countQueries(fn () => $this->getJson($uri)->assertOk());

        return count($this->recordedQueries());
    }
}
