<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use stdClass;

class PasswordResetTokenRepository
{
    public function storeHashed(string $phone, string $hashedOtp): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(
            ['phone' => $phone],
            [
                'token' => $hashedOtp,
                'attempts' => 0,
                'created_at' => now(),
            ]
        );
    }

    public function lockByPhone(string $phone): ?stdClass
    {
        return DB::table('password_reset_tokens')
            ->where('phone', $phone)
            ->lockForUpdate()
            ->first();
    }

    public function getByPhone(string $phone): ?stdClass
    {
        return DB::table('password_reset_tokens')->where('phone', $phone)->first();
    }

    public function incrementAttempts(string $phone): int
    {
        DB::table('password_reset_tokens')->where('phone', $phone)->increment('attempts');

        return (int) DB::table('password_reset_tokens')->where('phone', $phone)->value('attempts');
    }

    public function deleteByPhone(string $phone): void
    {
        DB::table('password_reset_tokens')->where('phone', $phone)->delete();
    }
}
