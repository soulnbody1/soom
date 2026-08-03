<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

trait EmitsStableKeys
{
    protected function stableRelation(string $relation, callable $map): ?array
    {
        if (! $this->resource->relationLoaded($relation) || $this->resource->{$relation} === null) {
            return null;
        }

        return $map($this->resource->{$relation});
    }

    protected function stableList(string $relation, string $resourceClass, Request $request): array
    {
        if (! $this->resource->relationLoaded($relation)) {
            return [];
        }

        return $resourceClass::collection($this->resource->{$relation})->toArray($request);
    }

    protected function stableCollection(Collection $items, string $resourceClass, Request $request): array
    {
        return $resourceClass::collection($items)->toArray($request);
    }
}
