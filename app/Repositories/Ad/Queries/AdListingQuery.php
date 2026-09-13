<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\DTO\Ad\AdFilterDTO;
use App\Models\Ad;
use App\Models\AttributeValue;
use App\Services\Ad\Support\AdKeywordFilter;
use App\Services\Ad\Support\CategoryTreeResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class AdListingQuery
{
    public function __construct(
        private readonly CategoryTreeResolver $categories,
        private readonly AdKeywordFilter $keywords,
    ) {}

    public function paginate(AdFilterDTO $filters, ?object $viewer): LengthAwarePaginator
    {
        $query = $this->apply(Ad::query(), $filters)
            ->with(Ad::$defaultRelations)
            ->withCount('views')
            ->withIsFavorite($viewer);

        return $this->applySort($query, $filters->sort)->paginate($filters->perPage);
    }

    /**
     * Sort keys are validated upstream; an unknown key falls back to newest first.
     * `most_viewed` relies on the `views_count` aggregate already selected above.
     */
    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            'most_viewed' => $query->orderByDesc('views_count')->orderByDesc('id'),
            default => $query->latest(),
        };
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
                $filters->userId !== null,
                fn (Builder $q): Builder => $q->where('user_id', $filters->userId)
            )
            ->when(
                $filters->attributes !== [],
                fn (Builder $q): Builder => $this->whereMatchesEveryAttribute($q, $filters->attributes)
            )
            ->when(
                $filters->keyword !== null && $filters->keyword !== '',
                fn (Builder $q): Builder => $this->keywords->apply($q, (string) $filters->keyword)
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
