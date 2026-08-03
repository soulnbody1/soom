<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use Carbon\CarbonInterface;

final class ReminderSchedule
{
    public const OVERDUE_OFFSET = 0;

    public static function normalizeSent(mixed $sent): array
    {
        $hours = array_map('intval', array_filter(
            (array) $sent,
            static fn ($value): bool => is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)
        ));

        return array_values(array_unique($hours));
    }

    public static function resolve(array $offsets, CarbonInterface $deadline, CarbonInterface $now, array $alreadySent): array
    {
        $pending = [];

        foreach ($offsets as $hoursBefore) {
            $hoursBefore = (int) $hoursBefore;

            if ($hoursBefore <= 0 || in_array($hoursBefore, $alreadySent, true)) {
                continue;
            }

            if ($now->greaterThanOrEqualTo($deadline->copy()->subHours($hoursBefore))) {
                $pending[] = $hoursBefore;
            }
        }

        sort($pending);

        if ($now->greaterThanOrEqualTo($deadline)) {
            if (in_array(self::OVERDUE_OFFSET, $alreadySent, true)) {
                return ['send' => null, 'record' => $pending];
            }

            return ['send' => self::OVERDUE_OFFSET, 'record' => [...$pending, self::OVERDUE_OFFSET]];
        }

        if ($pending === []) {
            return ['send' => null, 'record' => []];
        }

        return ['send' => $pending[0], 'record' => $pending];
    }

    public static function merge(array $alreadySent, array $newlySent): array
    {
        $merged = array_values(array_unique([...$alreadySent, ...$newlySent]));

        rsort($merged);

        return $merged;
    }
}
