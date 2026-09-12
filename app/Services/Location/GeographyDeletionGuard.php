<?php

declare(strict_types=1);

namespace App\Services\Location;

use App\Models\Ad;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GeographyDeletionGuard
{
    public function assertCountryDeletable(int $countryId): void
    {
        $stateIds = $this->stateIds($countryId);

        $this->assertEmpty(
            [$countryId],
            $stateIds,
            $this->cityIds($stateIds),
            'country',
            'لا يمكن حذف دولة مرتبطة بمستخدمين أو إعلانات أو مزادات.'
        );
    }

    public function assertStateDeletable(int $stateId): void
    {
        $this->assertEmpty(
            [],
            [$stateId],
            $this->cityIds([$stateId]),
            'state',
            'لا يمكن حذف محافظة مرتبطة بمستخدمين أو إعلانات أو مزادات.'
        );
    }

    public function assertCityDeletable(int $cityId): void
    {
        $this->assertEmpty([], [], [$cityId], 'city', 'لا يمكن حذف مدينة مرتبطة بمستخدمين أو إعلانات أو مزادات.');
    }

    private function assertEmpty(array $countryIds, array $stateIds, array $cityIds, string $key, string $message): void
    {
        $counts = [
            'ads' => $this->countAds($countryIds, $stateIds, $cityIds),
            'users' => $this->countUsers($countryIds, $stateIds, $cityIds),
            'auctions' => $this->countAuctions($countryIds, $stateIds, $cityIds),
        ];

        if (array_sum($counts) === 0) {
            return;
        }

        throw ValidationException::withMessages([
            $key => $message,
            'blocked_by' => $counts,
        ]);
    }

    private function countAds(array $countryIds, array $stateIds, array $cityIds): int
    {
        return Ad::withTrashed()
            ->where(fn ($query) => $this->matchAny($query, $countryIds, $stateIds, $cityIds))
            ->count();
    }

    private function countUsers(array $countryIds, array $stateIds, array $cityIds): int
    {
        return User::withTrashed()
            ->where(fn ($query) => $this->matchAny($query, $countryIds, $stateIds, $cityIds))
            ->count();
    }

    private function countAuctions(array $countryIds, array $stateIds, array $cityIds): int
    {
        return DB::table('auctions')
            ->where(fn ($query) => $this->matchAny($query, $countryIds, $stateIds, $cityIds))
            ->count();
    }

    private function matchAny($query, array $countryIds, array $stateIds, array $cityIds)
    {
        $query->whereRaw('1 = 0');

        if ($countryIds !== []) {
            $query->orWhereIn('country_id', $countryIds);
        }

        if ($stateIds !== []) {
            $query->orWhereIn('state_id', $stateIds);
        }

        if ($cityIds !== []) {
            $query->orWhereIn('city_id', $cityIds);
        }

        return $query;
    }

    private function stateIds(int $countryId): array
    {
        return DB::table('states')->where('country_id', $countryId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function cityIds(array $stateIds): array
    {
        if ($stateIds === []) {
            return [];
        }

        return DB::table('cities')->whereIn('state_id', $stateIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
