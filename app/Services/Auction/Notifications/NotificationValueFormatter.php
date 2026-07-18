<?php

declare(strict_types=1);

namespace App\Services\Auction\Notifications;

use App\Domain\Auction\ValueObjects\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

final class NotificationValueFormatter
{
    private const LOCALE = 'ar';

    public function text(string $key, array $params): string
    {
        return (string) Lang::get("auction.notifications.{$key}", $params, self::LOCALE);
    }

    public function money(int $minor, string $currency): string
    {
        try {
            return Money::fromMinorUnits(max(0, $minor), $currency)->toDecimalString();
        } catch (\InvalidArgumentException) {
            return (string) $minor;
        }
    }

    public function dateTime(?\DateTimeInterface $value): string
    {
        if (! $value) {
            return '—';
        }

        return Carbon::parse($value)
            ->timezone(config('app.timezone'))
            ->format('Y-m-d H:i');
    }
}
