<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\DeviceToken;
use App\Models\User;

final class DeviceTokenRegistry
{
    private const MIN_LENGTH = 32;

    private const MAX_LENGTH = 512;

    public function remember(User $user, ?string $token, ?string $platform = null): void
    {
        if (! $this->isPlausible($token)) {
            return;
        }

        DeviceToken::query()->updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'last_used_at' => now(),
            ]
        );
    }

    public function forget(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }

        DeviceToken::query()->where('token', $token)->delete();
    }

    public function forgetForUser(User $user, ?string $token = null): void
    {
        $query = DeviceToken::query()->where('user_id', $user->id);

        if ($token !== null && $token !== '') {
            $query->where('token', $token);
        }

        $query->delete();
    }

    /**
     * @return list<string>
     */
    public function tokensFor(int $userId): array
    {
        return DeviceToken::query()
            ->where('user_id', $userId)
            ->pluck('token')
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    public function tokensForMany(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'token'])
            ->groupBy('user_id')
            ->map(fn ($rows): array => $rows->pluck('token')->all())
            ->all();
    }

    public function isPlausible(?string $token): bool
    {
        return $token !== null
            && strlen($token) >= self::MIN_LENGTH
            && strlen($token) <= self::MAX_LENGTH
            && preg_match('/^[A-Za-z0-9_:\-\.]+$/', $token) === 1;
    }
}
