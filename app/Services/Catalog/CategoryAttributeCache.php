<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Http\Resources\AttributeResource;
use Illuminate\Support\Facades\Cache;

final class CategoryAttributeCache
{
    private const PREFIX = 'catalog:attributes:v';

    private const TTL_SECONDS = 604800;

    public function __construct(
        private readonly CategoryAttributeResolver $resolver,
        private readonly CatalogCacheVersion $version,
    ) {}

    public function forCategory(int $categoryId): array
    {
        return Cache::remember(
            self::PREFIX.$this->version->current().':'.$categoryId,
            self::TTL_SECONDS,
            fn (): array => AttributeResource::collection($this->resolver->resolve($categoryId))
                ->resolve(request())
        );
    }
}
