<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Domain\Ad\Enums\AdInteractionAction;
use App\Models\Ad;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class AdNotificationAudienceQuery
{
    public function idsAfter(Ad $ad, array $categoryIds, int $afterUserId, int $limit, int $threshold): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return DB::table('user_ad_interactions as interactions')
            ->select('interactions.user_id')
            ->join('ads as sources', function (JoinClause $join) use ($categoryIds): void {
                $join->on('sources.id', '=', 'interactions.ad_id')
                    ->whereIn('sources.category_id', $categoryIds);
            })
            ->join('users', 'users.id', '=', 'interactions.user_id')
            ->whereIn('interactions.action', [
                AdInteractionAction::Click->value,
                AdInteractionAction::Save->value,
            ])
            ->where('interactions.user_id', '>', $afterUserId)
            ->where('users.city_id', $ad->city_id)
            ->where('users.id', '!=', $ad->user_id)
            ->where('users.allow_ad_notifications', true)
            ->whereNull('users.deleted_at')
            ->groupBy('interactions.user_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->orderBy('interactions.user_id')
            ->limit($limit)
            ->pluck('interactions.user_id')
            ->map(static fn ($userId): int => (int) $userId)
            ->all();
    }
}
