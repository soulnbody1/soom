<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Concerns;

use App\Models\Attribute;
use App\Models\AttributeCategoryException;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

trait CreatesCatalogFixtures
{
    protected function category(?int $parentId = null, ?string $name = null, int $displayOrder = 0): Category
    {
        return Category::factory()->create(array_filter([
            'parent_id' => $parentId,
            'name' => $name,
            'display_order' => $displayOrder,
        ], static fn ($value): bool => $value !== null));
    }

    /**
     * @return Collection<int, Category>
     */
    protected function categoryChain(int $depth, ?int $rootParentId = null): Collection
    {
        $chain = new Collection;
        $parentId = $rootParentId;

        for ($level = 0; $level < $depth; $level++) {
            $category = $this->category($parentId);
            $chain->push($category);
            $parentId = $category->id;
        }

        return $chain;
    }

    protected function attribute(array $overrides = []): Attribute
    {
        return Attribute::factory()->create($overrides);
    }

    /**
     * @return Collection<int, AttributeOption>
     */
    protected function optionsFor(Attribute $attribute, array $values): Collection
    {
        return new Collection(array_map(
            fn (string $value): AttributeOption => AttributeOption::factory()->create([
                'attribute_id' => $attribute->id,
                'value' => $value,
                'label' => $value,
            ]),
            $values
        ));
    }

    protected function attachAttribute(Attribute $attribute, Category $category, bool $inheritable = true): void
    {
        $category->attributes()->syncWithoutDetaching([
            $attribute->id => ['is_inheritable' => $inheritable],
        ]);
    }

    protected function excludeAttribute(Attribute $attribute, Category $category): void
    {
        AttributeCategoryException::create([
            'attribute_id' => $attribute->id,
            'category_id' => $category->id,
        ]);
    }

    protected function catalogUser(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    protected function admin(): User
    {
        return $this->catalogUser('admin');
    }
}
