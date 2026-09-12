<?php

declare(strict_types=1);

namespace App\DTO\Ad;

use App\Http\Requests\Ad\AdIndexRequest;
use Illuminate\Http\Request;

final readonly class AdFilterDTO
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function __construct(
        public ?string $priceMin = null,
        public ?string $priceMax = null,
        public ?int $countryId = null,
        public ?int $stateId = null,
        public ?int $cityId = null,
        public ?int $categoryId = null,
        public array $attributes = [],
        public string $sort = 'latest',
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $keyword = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            priceMin: $request->filled('price_min') ? (string) $request->input('price_min') : null,
            priceMax: $request->filled('price_max') ? (string) $request->input('price_max') : null,
            countryId: $request->filled('country_id') ? (int) $request->input('country_id') : null,
            stateId: $request->filled('state_id') ? (int) $request->input('state_id') : null,
            cityId: $request->filled('city_id') ? (int) $request->input('city_id') : null,
            categoryId: $request->filled('category_id') ? (int) $request->input('category_id') : null,
            attributes: self::normalizeAttributes($request->input('attributes')),
            sort: self::normalizeSort($request->input('sort')),
            perPage: self::normalizePerPage($request->input('per_page')),
            keyword: $request->filled('title') ? trim((string) $request->input('title')) : null,
        );
    }

    private static function normalizeSort(mixed $sort): string
    {
        return in_array($sort, AdIndexRequest::SORTS, true) ? (string) $sort : 'latest';
    }

    private static function normalizePerPage(mixed $perPage): int
    {
        if (! is_numeric($perPage)) {
            return self::DEFAULT_PER_PAGE;
        }

        return max(1, min(self::MAX_PER_PAGE, (int) $perPage));
    }

    private static function normalizeAttributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $normalized = [];

        foreach ($attributes as $attributeId => $value) {
            $values = is_array($value) ? $value : explode(',', (string) $value);
            $values = array_values(array_filter(array_map('strval', $values), static fn (string $v): bool => $v !== ''));

            if ($values !== []) {
                $normalized[(int) $attributeId] = $values;
            }
        }

        return $normalized;
    }
}
