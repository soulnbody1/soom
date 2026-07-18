<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Dashboard;

use App\Services\Auction\Support\DashboardPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DashboardAuctionQuery
{
    use BuildsPeriodWindows;

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        $counts = [];
        foreach (DB::table('auctions')
            ->whereNull('deleted_at')
            ->selectRaw('status, count(*) as row_count')
            ->groupBy('status')
            ->get() as $row) {
            $counts[(string) $row->status] = (int) $row->row_count;
        }

        return $counts;
    }

    public function lifecycle(DashboardPeriod $period): array
    {
        $row = DB::table('auctions')
            ->whereNull('deleted_at')
            ->selectRaw(implode(', ', [
                $this->windowCount('created_at', $period, 'created_cur', 'created_prev'),
                $this->windowCount('started_at', $period, 'started_cur', 'started_prev'),
                $this->windowCount('completed_at', $period, 'completed_cur', 'completed_prev'),
                $this->windowSum("case when status = 'unsold' then 1 else 0 end", 'finalized_at', $period, 'unsold_cur', 'unsold_prev'),
                $this->windowCount('cancelled_at', $period, 'cancelled_cur', 'cancelled_prev'),
            ]), $this->windowBindings($period, 5))
            ->first();

        return array_map(static fn ($value) => $value === null ? null : (int) $value, (array) $row);
    }

    /**
     * Rates over auctions whose bidding ended inside the selected window.
     */
    public function endedPerformance(DashboardPeriod $period): array
    {
        $row = $this->bounded(DB::table('auctions')->whereNull('deleted_at'), 'ended_at', $period)
            ->whereNotNull('ended_at')
            ->leftJoin('auction_metrics', 'auction_metrics.auction_id', '=', 'auctions.id')
            ->selectRaw("
                count(*) as ended_count,
                coalesce(sum(case when auctions.winning_bid_id is not null then 1 else 0 end), 0) as sold_count,
                coalesce(sum(case when auctions.status = 'unsold' then 1 else 0 end), 0) as unsold_count,
                avg(auction_metrics.bids_count) as avg_bids,
                avg(auction_metrics.unique_bidders_count) as avg_bidders,
                avg(auction_metrics.extensions_count) as avg_extensions,
                coalesce(sum(auction_metrics.extensions_count), 0) as extensions_total
            ")
            ->first();

        return [
            'ended_count' => (int) $row->ended_count,
            'sold_count' => (int) $row->sold_count,
            'unsold_count' => (int) $row->unsold_count,
            'avg_bids' => $row->avg_bids === null ? null : round((float) $row->avg_bids, 1),
            'avg_bidders' => $row->avg_bidders === null ? null : round((float) $row->avg_bidders, 1),
            'avg_extensions' => $row->avg_extensions === null ? null : round((float) $row->avg_extensions, 1),
            'extensions_total' => (int) $row->extensions_total,
        ];
    }

    /**
     * Uplift and reserve achievement from completed settlements, winner
     * defaults and reassignments — all final records, never recomputed
     * from mutable auction configuration.
     */
    public function settlementPerformance(DashboardPeriod $period): array
    {
        $completed = $this->bounded(
            DB::table('auction_settlements')
                ->join('auctions', 'auctions.id', '=', 'auction_settlements.auction_id')
                ->where('auction_settlements.status', 'completed'),
            'auction_settlements.completed_at',
            $period
        )
            ->whereNotNull('auction_settlements.completed_at')
            ->selectRaw('
                count(*) as completed_count,
                avg(case when auctions.starting_amount_minor > 0
                    then (auction_settlements.winning_amount_minor - auctions.starting_amount_minor) * 100.0 / auctions.starting_amount_minor
                    end) as avg_uplift_pct,
                coalesce(sum(case when auctions.reserve_amount_minor is not null then 1 else 0 end), 0) as reserve_count,
                coalesce(sum(case when auctions.reserve_amount_minor is not null
                    and auction_settlements.winning_amount_minor >= auctions.reserve_amount_minor then 1 else 0 end), 0) as reserve_met_count
            ')
            ->first();

        $defaults = $this->bounded(
            DB::table('auction_settlements')->where('status', 'defaulted'),
            'defaulted_at',
            $period
        )->whereNotNull('defaulted_at')->count();

        $reassignments = $this->bounded(DB::table('auction_winner_reassignments'), 'created_at', $period)->count();

        return [
            'settled_count' => (int) $completed->completed_count,
            'avg_uplift_pct' => $completed->avg_uplift_pct === null ? null : round((float) $completed->avg_uplift_pct, 1),
            'reserve_count' => (int) $completed->reserve_count,
            'reserve_met_count' => (int) $completed->reserve_met_count,
            'winner_defaults' => $defaults,
            'reassignments' => $reassignments,
        ];
    }

    public function participation(DashboardPeriod $period): array
    {
        $bids = $this->bounded(DB::table('auction_bids'), 'accepted_at', $period)
            ->selectRaw('count(*) as total_bids, count(distinct bidder_id) as unique_bidders, count(distinct auction_id) as auctions_with_bids')
            ->first();

        $participants = $this->bounded(DB::table('auction_participants'), 'registered_at', $period)
            ->selectRaw('count(*) as registrations, count(distinct user_id) as unique_users, coalesce(sum(case when qualified_at is not null then 1 else 0 end), 0) as qualified')
            ->first();

        return [
            'total_bids' => (int) $bids->total_bids,
            'unique_bidders' => (int) $bids->unique_bidders,
            'auctions_with_bids' => (int) $bids->auctions_with_bids,
            'registrations' => (int) $participants->registrations,
            'unique_participants' => (int) $participants->unique_users,
            'qualified_participants' => (int) $participants->qualified,
        ];
    }

    /** @return array<int, object> */
    public function topAuctionsByBids(DashboardPeriod $period, int $limit = 5): array
    {
        return $this->bounded(DB::table('auction_bids'), 'auction_bids.accepted_at', $period)
            ->join('auctions', 'auctions.id', '=', 'auction_bids.auction_id')
            ->selectRaw('
                auctions.public_id,
                auctions.title,
                auctions.status,
                count(*) as bid_count,
                count(distinct auction_bids.bidder_id) as bidder_count,
                max(auction_bids.amount_minor) as top_amount_minor,
                max(auctions.currency_code) as currency_code
            ')
            ->groupBy('auction_bids.auction_id', 'auctions.public_id', 'auctions.title', 'auctions.status')
            ->orderByDesc('bid_count')
            ->limit($limit)
            ->get()
            ->all();
    }

    private function bounded(Builder $query, string $column, DashboardPeriod $period): Builder
    {
        if ($period->hasBounds()) {
            $query->whereBetween($column, [$period->from->toDateTimeString(), $period->to->toDateTimeString()]);
        }

        return $query;
    }
}
