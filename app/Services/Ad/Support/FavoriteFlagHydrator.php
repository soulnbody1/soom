<?php

declare(strict_types=1);

namespace App\Services\Ad\Support;

use App\Models\Favorite;

final class FavoriteFlagHydrator
{
    public function favoritedAdIds(?int $userId, array $adIds): array
    {
        if ($userId === null || $adIds === []) {
            return [];
        }

        return array_flip(
            Favorite::query()
                ->where('user_id', $userId)
                ->whereIn('ad_id', $adIds)
                ->pluck('ad_id')
                ->map(static fn ($adId): int => (int) $adId)
                ->all()
        );
    }
}
