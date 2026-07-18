<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use Carbon\CarbonImmutable;

final readonly class DashboardPeriod
{
    public function __construct(
        public string $key,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public ?CarbonImmutable $previousFrom,
        public ?CarbonImmutable $previousTo,
    ) {}

    public static function resolve(string $key, ?string $dateFrom, ?string $dateTo): self
    {
        $timezone = config('app.timezone');
        $now = CarbonImmutable::now($timezone);

        [$from, $to] = match ($key) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfDay()],
            'prev_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->startOfYear(), $now->endOfDay()],
            'custom' => [
                CarbonImmutable::parse((string) $dateFrom, $timezone)->startOfDay(),
                CarbonImmutable::parse((string) $dateTo, $timezone)->endOfDay(),
            ],
            default => [null, null],
        };

        if ($from === null || $to === null) {
            return new self('all', null, null, null, null);
        }

        $lengthSeconds = $from->diffInSeconds($to) + 1;

        return new self(
            $key,
            $from,
            $to,
            $from->subSeconds($lengthSeconds),
            $from->subSecond(),
        );
    }

    public function hasBounds(): bool
    {
        return $this->from !== null && $this->to !== null;
    }

    public function hasPrevious(): bool
    {
        return $this->previousFrom !== null && $this->previousTo !== null;
    }

    public function lengthInDays(): int
    {
        if (! $this->hasBounds()) {
            return 0;
        }

        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function toMeta(): array
    {
        return [
            'key' => $this->key,
            'from' => $this->from?->toIso8601String(),
            'to' => $this->to?->toIso8601String(),
            'previous_from' => $this->previousFrom?->toIso8601String(),
            'previous_to' => $this->previousTo?->toIso8601String(),
            'timezone' => config('app.timezone'),
        ];
    }
}
