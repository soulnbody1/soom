<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Domain\Auth\Enums\OtpVerificationStatus;
use App\Repositories\PasswordResetTokenRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class OtpManager
{
    public function __construct(private readonly PasswordResetTokenRepository $tokens) {}

    public function generate(): string
    {
        $length = (int) config('otp.length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    public function issue(string $phone, string $otp): void
    {
        $this->tokens->storeHashed($phone, Hash::make($otp));
    }

    public function verifyAndConsume(string $phone, string $otp): OtpVerificationStatus
    {
        return DB::transaction(function () use ($phone, $otp): OtpVerificationStatus {
            $record = $this->tokens->lockByPhone($phone);

            if ($record === null) {
                return OtpVerificationStatus::Invalid;
            }

            if ($this->isExpired($record)) {
                $this->tokens->deleteByPhone($phone);

                return OtpVerificationStatus::Expired;
            }

            if ((int) $record->attempts >= (int) config('otp.max_verification_attempts')) {
                $this->tokens->deleteByPhone($phone);

                return OtpVerificationStatus::TooManyAttempts;
            }

            if (! Hash::check($otp, (string) $record->token)) {
                $attempts = $this->tokens->incrementAttempts($phone);

                if ($attempts >= (int) config('otp.max_verification_attempts')) {
                    $this->tokens->deleteByPhone($phone);

                    return OtpVerificationStatus::TooManyAttempts;
                }

                return OtpVerificationStatus::Invalid;
            }

            $this->tokens->deleteByPhone($phone);

            return OtpVerificationStatus::Valid;
        });
    }

    public function invalidate(string $phone): void
    {
        $this->tokens->deleteByPhone($phone);
    }

    private function isExpired(object $record): bool
    {
        if ($record->created_at === null) {
            return true;
        }

        return Carbon::parse($record->created_at)
            ->addMinutes((int) config('otp.ttl_minutes'))
            ->isPast();
    }
}
