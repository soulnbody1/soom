<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use Illuminate\Support\Carbon;

final readonly class MetricsRange extends BaseContentReviewDTO
{
    public const TODAY = 'today';

    public const LAST_7_DAYS = '7d';

    public const LAST_30_DAYS = '30d';

    public const CUSTOM = 'custom';

    public function __construct(
        public string $key,
        public Carbon $from,
        public Carbon $to,
    ) {}

    public static function presets(): array
    {
        return [self::TODAY, self::LAST_7_DAYS, self::LAST_30_DAYS];
    }

    public static function preset(string $key): self
    {
        $now = Carbon::now();

        return match ($key) {
            self::TODAY => new self(self::TODAY, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            self::LAST_30_DAYS => new self(self::LAST_30_DAYS, $now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()),
            default => new self(self::LAST_7_DAYS, $now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()),
        };
    }

    public static function custom(Carbon $from, Carbon $to): self
    {
        return new self(self::CUSTOM, $from->copy()->startOfDay(), $to->copy()->endOfDay());
    }

    public function days(): int
    {
        return max(1, (int) $this->from->diffInDays($this->to) + 1);
    }

    public function cacheKey(): string
    {
        return $this->key.':'.$this->from->getTimestamp().':'.$this->to->getTimestamp();
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'days' => $this->days(),
        ];
    }
}
