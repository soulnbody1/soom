<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Dashboard;

use App\Services\Auction\Support\DashboardPeriod;

/**
 * Builds conditional-aggregate SQL so one query returns both the selected
 * window and the comparable previous window. Each fragment consumes four
 * bindings (current from/to, previous from/to) in declaration order.
 */
trait BuildsPeriodWindows
{
    private function windowSum(string $expression, string $dateColumn, DashboardPeriod $period, string $currentAlias, string $previousAlias): string
    {
        if (! $period->hasBounds()) {
            return "coalesce(sum({$expression}), 0) as {$currentAlias}, null as {$previousAlias}";
        }

        return "coalesce(sum(case when {$dateColumn} between ? and ? then ({$expression}) else 0 end), 0) as {$currentAlias}, "
            ."coalesce(sum(case when {$dateColumn} between ? and ? then ({$expression}) else 0 end), 0) as {$previousAlias}";
    }

    private function windowCount(string $dateColumn, DashboardPeriod $period, string $currentAlias, string $previousAlias): string
    {
        return $this->windowSum('1', $dateColumn, $period, $currentAlias, $previousAlias);
    }

    private function windowBindings(DashboardPeriod $period, int $fragmentCount): array
    {
        if (! $period->hasBounds()) {
            return [];
        }

        $single = [
            $period->from->toDateTimeString(),
            $period->to->toDateTimeString(),
            $period->previousFrom->toDateTimeString(),
            $period->previousTo->toDateTimeString(),
        ];

        return array_merge(...array_fill(0, $fragmentCount, $single));
    }
}
