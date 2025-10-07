<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class RefreshTokenRepository
{
    public function updateOrInsertToken(int $userId, string $hashedToken): void
    {
        DB::table('refresh_tokens')->updateOrInsert(
            ['user_id' => $userId],
            [
                'token' => $hashedToken,
                'expires_at' => now()->addDays(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function findValidToken(string $hashedToken)
    {
        return DB::table('refresh_tokens')
            ->where('token', $hashedToken)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function deleteTokenByUserId(int $userId): void
    {
        DB::table('refresh_tokens')->where('user_id', $userId)->delete();
    }
}
