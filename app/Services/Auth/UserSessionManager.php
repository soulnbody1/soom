<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Notification\DeviceTokenRegistry;
use App\Services\TokenService;

final class UserSessionManager
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly DeviceTokenRegistry $devices,
    ) {}

    public function issue(User $user, ?string $fcmToken = null): array
    {
        $this->rememberFcmToken($user, $fcmToken);

        return $this->tokens->createTokens($user);
    }

    public function reissue(User $user, ?string $fcmToken = null): array
    {
        $this->revokeAll($user);

        return $this->issue($user, $fcmToken);
    }

    public function revokeAll(User $user, ?string $fcmToken = null): void
    {
        $user->tokens()->delete();
        $this->tokens->revokeRefreshToken($user->id);

        if ($fcmToken !== null && $fcmToken !== '') {
            $this->devices->forget($fcmToken);

            if ($user->fcm_token === $fcmToken) {
                $user->forceFill(['fcm_token' => null])->save();
            }

            return;
        }

        $this->devices->forgetForUser($user);
        $user->forceFill(['fcm_token' => null])->save();
    }

    private function rememberFcmToken(User $user, ?string $fcmToken): void
    {
        if (! $this->devices->isPlausible($fcmToken)) {
            return;
        }

        $this->devices->remember($user, $fcmToken);
        $user->forceFill(['fcm_token' => $fcmToken])->save();
    }
}
