<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Dashboard;

use App\Services\Auction\Support\DashboardPeriod;
use App\Services\Market\MarketQuery;
use Illuminate\Database\Query\Builder;

final class DashboardSeriesQuery
{
    public function __construct(private readonly MarketQuery $markets) {}

    public function granularity(DashboardPeriod $period): string
    {
        if (! $period->hasBounds() || $period->lengthInDays() > 92) {
            return 'month';
        }

        return 'day';
    }

    /** @return array<int, array<string, int|string>> */
    public function financialSeries(DashboardPeriod $period): array
    {
        $granularity = $this->granularity($period);

        $buckets = [];

        $this->mergeSeries($buckets, 'revenue_minor', $this->grouped(
            $this->markets->table('auction_settlements')->where('status', 'completed')->whereNotNull('completed_at'),
            'completed_at', 'sum(platform_fee_minor)', $period, $granularity
        ));
        $this->mergeSeries($buckets, 'gross_minor', $this->grouped(
            $this->markets->table('auction_settlements')->where('status', 'completed')->whereNotNull('completed_at'),
            'completed_at', 'sum(winning_amount_minor)', $period, $granularity
        ));
        $this->mergeSeries($buckets, 'payouts_paid_minor', $this->grouped(
            $this->markets->table('auction_seller_payouts')->where('status', 'paid')->whereNotNull('paid_at'),
            'paid_at', 'sum(amount_minor)', $period, $granularity
        ));
        $this->mergeSeries($buckets, 'refunds_succeeded_minor', $this->grouped(
            $this->markets->table('refund_transactions')->where('status', 'succeeded')->whereNotNull('succeeded_at'),
            'succeeded_at', 'sum(amount_minor)', $period, $granularity
        ));

        return $this->fill($buckets, $period, $granularity, ['revenue_minor', 'gross_minor', 'payouts_paid_minor', 'refunds_succeeded_minor']);
    }

    /** @return array<int, array<string, int|string>> */
    public function lifecycleSeries(DashboardPeriod $period): array
    {
        $granularity = $this->granularity($period);
        $auctions = fn (): Builder => $this->markets->table('auctions')->whereNull('deleted_at');

        $buckets = [];
        $this->mergeSeries($buckets, 'created', $this->grouped($auctions(), 'created_at', 'count(*)', $period, $granularity));
        $this->mergeSeries($buckets, 'started', $this->grouped($auctions()->whereNotNull('started_at'), 'started_at', 'count(*)', $period, $granularity));
        $this->mergeSeries($buckets, 'completed', $this->grouped($auctions()->whereNotNull('completed_at'), 'completed_at', 'count(*)', $period, $granularity));
        $this->mergeSeries($buckets, 'unsold', $this->grouped($auctions()->where('status', 'unsold')->whereNotNull('finalized_at'), 'finalized_at', 'count(*)', $period, $granularity));
        $this->mergeSeries($buckets, 'cancelled', $this->grouped($auctions()->whereNotNull('cancelled_at'), 'cancelled_at', 'count(*)', $period, $granularity));

        return $this->fill($buckets, $period, $granularity, ['created', 'started', 'completed', 'unsold', 'cancelled']);
    }

    /** @return array<string, int> */
    private function grouped(Builder $query, string $dateColumn, string $aggregate, DashboardPeriod $period, string $granularity): array
    {
        if ($period->hasBounds()) {
            $query->whereBetween($dateColumn, [$period->from->toDateTimeString(), $period->to->toDateTimeString()]);
        }

        $bucketExpression = $granularity === 'day'
            ? "date({$dateColumn})"
            : "date_format({$dateColumn}, '%Y-%m-01')";

        $series = [];
        foreach ($query
            ->selectRaw("{$bucketExpression} as bucket, coalesce({$aggregate}, 0) as value")
            ->groupBy('bucket')
            ->get() as $row) {
            $series[(string) $row->bucket] = (int) $row->value;
        }

        return $series;
    }

    private function mergeSeries(array &$buckets, string $key, array $series): void
    {
        foreach ($series as $bucket => $value) {
            $buckets[$bucket][$key] = $value;
        }
    }

    /** @return array<int, array<string, int|string>> */
    private function fill(array $buckets, DashboardPeriod $period, string $granularity, array $keys): array
    {
        $dates = [];
        if ($period->hasBounds()) {
            $cursor = $granularity === 'day' ? $period->from->startOfDay() : $period->from->startOfMonth();
            while ($cursor->lessThanOrEqualTo($period->to)) {
                $dates[] = $cursor->format('Y-m-d');
                $cursor = $granularity === 'day' ? $cursor->addDay() : $cursor->addMonth();
            }
        } else {
            $dates = array_keys($buckets);
            sort($dates);
        }

        $rows = [];
        foreach ($dates as $date) {
            $row = ['date' => $date];
            foreach ($keys as $key) {
                $row[$key] = (int) ($buckets[$date][$key] ?? 0);
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
