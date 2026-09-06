<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

class OtpRateLimiterService
{
    public function check(string $phone, ?string $ip = null): ?array
    {
        $phone = (string) preg_replace('/\D/', '', $phone);

        $cooldownKey = 'otp_cooldown:'.$phone;
        $blockKey = 'otp_block:'.$phone;
        $ipKey = 'otp_ip:'.($ip ?? 'unknown');

        $maxPerWindow = (int) config('otp.send.max_per_window');
        $maxPerIp = (int) config('otp.send.max_per_ip_per_hour');

        if (RateLimiter::tooManyAttempts($blockKey, $maxPerWindow)) {
            return $this->blocked(
                'لقد تجاوزت عدد المحاولات المسموح بها. الرجاء المحاولة بعد '
                .gmdate('H:i:s', RateLimiter::availableIn($blockKey))
            );
        }

        if (RateLimiter::tooManyAttempts($ipKey, $maxPerIp)) {
            return $this->blocked(
                'لقد تجاوزت عدد المحاولات المسموح بها. الرجاء المحاولة بعد '
                .gmdate('H:i:s', RateLimiter::availableIn($ipKey))
            );
        }

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return $this->blocked(
                'لقد قمت بطلب رمز مؤخرًا. الرجاء المحاولة بعد '
                .gmdate('i:s', RateLimiter::availableIn($cooldownKey))
            );
        }

        RateLimiter::hit($cooldownKey, (int) config('otp.send.cooldown_seconds'));
        RateLimiter::hit($blockKey, (int) config('otp.send.window_seconds'));
        RateLimiter::hit($ipKey, 3600);

        return null;
    }

    private function blocked(string $message): array
    {
        return ['status' => false, 'message' => $message, 'code' => 429];
    }
}
