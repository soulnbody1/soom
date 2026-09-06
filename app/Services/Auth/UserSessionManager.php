<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\TokenService;

final class UserSessionManager
{
    public function __construct(private readonly TokenService $tokens) {}

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

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
        $this->tokens->revokeRefreshToken($user->id);
    }

    private function rememberFcmToken(User $user, ?string $fcmToken): void
    {
        if (! empty($fcmToken)) {
            $user->update(['fcm_token' => $fcmToken]);
        }
    }
}
