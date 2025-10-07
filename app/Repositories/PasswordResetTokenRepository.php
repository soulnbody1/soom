<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class PasswordResetTokenRepository
{
    public function storeOrUpdate(string $phone, string $otp)
    {
        return DB::table('password_reset_tokens')->updateOrInsert(
            ['phone' => $phone],
            [
                'token' => $otp,
                'created_at' => now()
            ]
        );
    }
    public function storeOrUpdateWhatsApp(string $phone, string $otp)
    {
        return DB::table('password_reset_tokens')->updateOrInsert(
            ['phone' => $phone],
            [
                'token' => $otp,
                'created_at' => now()
            ]
        );
    }

    public function getByPhone(string $phone)
    {
        return DB::table('password_reset_tokens')->where('phone', $phone)->first();
    }

    public function deleteByPhone(string $phone)
    {
        return DB::table('password_reset_tokens')->where('phone', $phone)->delete();
    }
}
