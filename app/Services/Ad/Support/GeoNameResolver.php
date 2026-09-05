<?php

declare(strict_types=1);

namespace App\Services\Ad\Support;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Support\Facades\Cache;

final class GeoNameResolver
{
    private const CACHE_KEY = 'ads:geo_names';

    private const TTL_SECONDS = 86400;

    private ?array $names = null;

    public function matchingIds(string $keyword): array
    {
        $needle = trim($keyword);

        if ($needle === '') {
            return ['country_ids' => [], 'state_ids' => [], 'city_ids' => []];
        }

        $names = $this->names();

        return [
            'country_ids' => $this->match($names['countries'], $needle),
            'state_ids' => $this->match($names['states'], $needle),
            'city_ids' => $this->match($names['cities'], $needle),
        ];
    }

    public function forget(): void
    {
        $this->names = null;
        Cache::forget(self::CACHE_KEY);
    }

    private function match(array $rows, string $needle): array
    {
        return array_values(array_keys(array_filter(
            $rows,
            static fn (string $name): bool => mb_stripos($name, $needle) !== false
        )));
    }

    private function names(): array
    {
        return $this->names ??= Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, static fn (): array => [
            'countries' => Country::query()->pluck('name', 'id')->all(),
            'states' => State::query()->pluck('name', 'id')->all(),
            'cities' => City::query()->pluck('name', 'id')->all(),
        ]);
    }
}
