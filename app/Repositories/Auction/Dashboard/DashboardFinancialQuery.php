<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Dashboard;

use App\Services\Auction\Support\DashboardPeriod;
use Illuminate\Support\Facades\DB;

final class DashboardFinancialQuery
{
    use BuildsPeriodWindows;

    /**
     * Recognized commissions and gross sold value from completed settlements.
     */
    public function revenueAndGross(DashboardPeriod $period): array
    {
        $row = DB::table('auction_settlements')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->selectRaw(implode(', ', [
                $this->windowSum('platform_fee_minor', 'completed_at', $period, 'fee_cur', 'fee_prev'),
                $this->windowSum('winning_amount_minor', 'completed_at', $period, 'gross_cur', 'gross_prev'),
                $this->windowCount('completed_at', $period, 'sold_cur', 'sold_prev'),
            ]), $this->windowBindings($period, 3))
            ->first();

        return $this->intValues((array) $row);
    }

    public function forfeitedDeposits(DashboardPeriod $period): array
    {
        $row = DB::table('auction_deposits')
            ->where('status', 'forfeited')
            ->selectRaw(implode(', ', [
                $this->windowSum('forfeited_amount_minor', 'released_at', $period, 'forfeited_cur', 'forfeited_prev'),
            ]), $this->windowBindings($period, 1))
            ->first();

        return $this->intValues((array) $row);
    }

    /**
     * Snapshot of money currently held, split so nothing is double counted:
     * held deposits exclude refund-pending rows (those appear as refund
     * liabilities) and winner funds only cover not-yet-completed settlements
     * (completed ones surface as seller payout liabilities + revenue).
     */
    public function heldFunds(): array
    {
        $deposits = DB::table('auction_deposits')
            ->where('status', 'held')
            ->selectRaw("
                coalesce(sum(case when type = 'bidder' then held_amount_minor else 0 end), 0) as bidder_minor,
                coalesce(sum(case when type = 'bidder' then 1 else 0 end), 0) as bidder_count,
                coalesce(sum(case when type = 'seller' then held_amount_minor else 0 end), 0) as seller_minor,
                coalesce(sum(case when type = 'seller' then 1 else 0 end), 0) as seller_count
            ")
            ->first();

        $winner = DB::table('auction_settlements')
            ->where('is_current', true)
            ->whereIn('status', ['payment_pending', 'paid', 'handover_pending', 'disputed'])
            ->whereRaw('(amount_paid_minor + deposit_applied_minor) > 0')
            ->selectRaw('coalesce(sum(amount_paid_minor + deposit_applied_minor), 0) as winner_minor, count(*) as winner_count')
            ->first();

        return $this->intValues(array_merge((array) $deposits, (array) $winner));
    }

    /**
     * @return array{statuses: array<string, array{count: int, amount_minor: int}>, paid_cur_minor: int, paid_cur_count: int, paid_prev_minor: int|null, paid_prev_count: int|null}
     */
    public function payoutSummary(DashboardPeriod $period): array
    {
        $statuses = [];
        foreach (DB::table('auction_seller_payouts')
            ->selectRaw('status, count(*) as row_count, coalesce(sum(amount_minor), 0) as total_minor')
            ->groupBy('status')
            ->get() as $row) {
            $statuses[(string) $row->status] = [
                'count' => (int) $row->row_count,
                'amount_minor' => (int) $row->total_minor,
            ];
        }

        $paid = DB::table('auction_seller_payouts')
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->selectRaw(implode(', ', [
                $this->windowSum('amount_minor', 'paid_at', $period, 'paid_cur_minor', 'paid_prev_minor'),
                $this->windowCount('paid_at', $period, 'paid_cur_count', 'paid_prev_count'),
            ]), $this->windowBindings($period, 2))
            ->first();

        return ['statuses' => $statuses] + $this->intValues((array) $paid);
    }

    /**
     * @return array{statuses: array<string, array{count: int, amount_minor: int}>, succeeded_cur_minor: int, succeeded_cur_count: int, succeeded_prev_minor: int|null, succeeded_prev_count: int|null}
     */
    public function refundSummary(DashboardPeriod $period): array
    {
        $statuses = [];
        foreach (DB::table('refund_transactions')
            ->selectRaw('status, count(*) as row_count, coalesce(sum(amount_minor), 0) as total_minor')
            ->groupBy('status')
            ->get() as $row) {
            $statuses[(string) $row->status] = [
                'count' => (int) $row->row_count,
                'amount_minor' => (int) $row->total_minor,
            ];
        }

        $succeeded = DB::table('refund_transactions')
            ->where('status', 'succeeded')
            ->whereNotNull('succeeded_at')
            ->selectRaw(implode(', ', [
                $this->windowSum('amount_minor', 'succeeded_at', $period, 'succeeded_cur_minor', 'succeeded_prev_minor'),
                $this->windowCount('succeeded_at', $period, 'succeeded_cur_count', 'succeeded_prev_count'),
            ]), $this->windowBindings($period, 2))
            ->first();

        return ['statuses' => $statuses] + $this->intValues((array) $succeeded);
    }

    public function winnerCollections(DashboardPeriod $period): array
    {
        $pending = DB::table('payment_submissions')
            ->where('purpose', 'winner_settlement')
            ->where('status', 'pending_review')
            ->selectRaw('count(*) as pending_count, coalesce(sum(amount_minor), 0) as pending_minor, min(submitted_at) as oldest_submitted_at')
            ->first();

        $reviewed = DB::table('payment_submissions')
            ->where('purpose', 'winner_settlement')
            ->whereNotNull('reviewed_at')
            ->selectRaw(implode(', ', [
                $this->windowSum("case when status = 'approved' then amount_minor else 0 end", 'reviewed_at', $period, 'approved_cur_minor', 'approved_prev_minor'),
                $this->windowSum("case when status = 'approved' then 1 else 0 end", 'reviewed_at', $period, 'approved_cur_count', 'approved_prev_count'),
                $this->windowSum("case when status = 'rejected' then 1 else 0 end", 'reviewed_at', $period, 'rejected_cur_count', 'rejected_prev_count'),
            ]), $this->windowBindings($period, 3))
            ->first();

        $awaiting = DB::table('auction_settlements')
            ->where('is_current', true)
            ->where('status', 'payment_pending')
            ->selectRaw("
                count(*) as awaiting_count,
                coalesce(sum(remaining_amount_minor), 0) as awaiting_minor,
                coalesce(sum(case when payment_due_at is not null and payment_due_at < now() then 1 else 0 end), 0) as overdue_count,
                coalesce(sum(case when payment_due_at is not null and payment_due_at < now() then remaining_amount_minor else 0 end), 0) as overdue_minor,
                coalesce(sum(case when payment_due_at is not null and payment_due_at >= now() and payment_due_at < date_add(now(), interval 24 hour) then 1 else 0 end), 0) as due_soon_count
            ")
            ->first();

        return array_merge(
            $this->intValues((array) $reviewed),
            $this->intValues((array) $awaiting),
            [
                'pending_count' => (int) $pending->pending_count,
                'pending_minor' => (int) $pending->pending_minor,
                'oldest_submitted_at' => $pending->oldest_submitted_at,
            ],
        );
    }

    private function intValues(array $row): array
    {
        return array_map(static fn ($value) => $value === null ? null : (int) $value, $row);
    }
}
