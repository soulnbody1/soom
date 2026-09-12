<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Notifications;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class AdminAlertRecipientResolver
{
    private const CHUNK = 500;

    private const COOLDOWN_PREFIX = 'content_review:alert:';

    public function recipients(): Collection
    {
        $recipients = collect();

        User::query()
            ->where('role', 'admin')
            ->select(['id', 'name', 'role'])
            ->chunkById(self::CHUNK, function (Collection $users) use (&$recipients): void {
                $recipients = $recipients->concat($users);
            });

        return $recipients;
    }

    public function shouldAlert(string $eventType, ?string $scopeKey): bool
    {
        $scope = ContentReviewNotificationCatalog::scope($eventType);
        $scoped = $scope !== ContentReviewNotificationCatalog::SCOPE_GLOBAL && $scopeKey !== null && $scopeKey !== '';

        $key = $scoped
            ? self::COOLDOWN_PREFIX.$eventType.':'.$scopeKey
            : self::COOLDOWN_PREFIX.$eventType;

        $seconds = $scope === ContentReviewNotificationCatalog::SCOPE_SUBJECT
            ? (int) config('content_review.alerts.per_subject_cooldown_seconds', 3600)
            : (int) config('content_review.alerts.global_cooldown_seconds', 3600);

        return Cache::add($key, 1, max(1, $seconds));
    }
}
