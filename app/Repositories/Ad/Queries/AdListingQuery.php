<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\DTO\Ad\AdFilterDTO;
use App\Models\Ad;
use App\Models\AttributeValue;
use App\Services\Ad\Support\CategoryTreeResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class AdListingQuery
{
    private const PER_PAGE = 20;

    public function __construct(private readonly CategoryTreeResolver $categories) {}

    public function paginate(AdFilterDTO $filters, ?object $viewer): LengthAwarePaginator
    {
        return $this->apply(Ad::query(), $filters)
            ->latest()
            ->with(Ad::$defaultRelations)
            ->withIsFavorite($viewer)
            ->paginate(self::PER_PAGE);
    }

    public function apply(Builder $query, AdFilterDTO $filters): Builder
    {
        return $query
            ->when(
                $filters->priceMin !== null,
                fn (Builder $q): Builder => $q->where('price', '>=', $filters->priceMin)
            )
            ->when(
                $filters->priceMax !== null,
                fn (Builder $q): Builder => $q->where('price', '<=', $filters->priceMax)
            )
            ->when(
                $filters->countryId !== null,
                fn (Builder $q): Builder => $q->where('country_id', $filters->countryId)
            )
            ->when(
                $filters->stateId !== null,
                fn (Builder $q): Builder => $q->where('state_id', $filters->stateId)
            )
            ->when(
                $filters->cityId !== null,
                fn (Builder $q): Builder => $q->where('city_id', $filters->cityId)
            )
            ->when(
                $filters->categoryId !== null,
                fn (Builder $q): Builder => $q->whereIn(
                    'category_id',
                    $this->categories->subtreeIds($filters->categoryId)
                )
            )
            ->when(
                $filters->attributes !== [],
                fn (Builder $q): Builder => $this->whereMatchesEveryAttribute($q, $filters->attributes)
            );
    }

    private function whereMatchesEveryAttribute(Builder $query, array $attributes): Builder
    {
        $matchingAdIds = AttributeValue::query()
            ->select('ad_id')
            ->where(function (Builder $group) use ($attributes): void {
                foreach ($attributes as $attributeId => $values) {
                    $group->orWhere(fn (Builder $pair): Builder => $pair
                        ->where('attribute_id', $attributeId)
                        ->whereIn('value', $values));
                }
            })
            ->groupBy('ad_id')
            ->havingRaw('COUNT(DISTINCT attribute_id) = ?', [count($attributes)]);

        return $query->whereIn('ads.id', $matchingAdIds);
    }
}
