<?php

declare(strict_types=1);

namespace App\Services\Ad\Support;

use App\Services\Support\FulltextQuerySanitizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * One keyword-matching implementation shared by the dedicated search endpoint and
 * the filtered listing endpoint, so a text query can be combined with price,
 * location, category, and attribute filters without diverging in behaviour.
 */
final class AdKeywordFilter
{
    public function __construct(
        private readonly GeoNameResolver $locations,
        private readonly FulltextQuerySanitizer $sanitizer,
    ) {}

    public function apply(Builder $query, string $keyword): Builder
    {
        $expression = $this->sanitizer->toBooleanMode($keyword);
        $locations = $this->locations->matchingIds($keyword);

        return $query->where(function (Builder $group) use ($expression, $keyword, $locations): void {
            if ($expression !== null) {
                $group->whereRaw('MATCH(title, description) AGAINST(? IN BOOLEAN MODE)', [$expression]);
            } else {
                $prefix = $this->sanitizer->toLikePrefix($keyword);
                $group->where('title', 'LIKE', $prefix)
                    ->orWhere('description', 'LIKE', $prefix);
            }

            foreach (['country_ids' => 'country_id', 'state_ids' => 'state_id', 'city_ids' => 'city_id'] as $key => $column) {
                if ($locations[$key] !== []) {
                    $group->orWhereIn($column, $locations[$key]);
                }
            }
        });
    }
}
