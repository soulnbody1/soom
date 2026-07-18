<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Dashboard;

use Illuminate\Support\Facades\DB;

final class DashboardActionQuery
{
    private const RECENT_LIMIT = 5;

    public function queueAges(): array
    {
        $submissions = DB::table('payment_submissions')
            ->where('status', 'pending_review')
            ->selectRaw('count(*) as pending_count, min(submitted_at) as oldest_at')
            ->first();

        $refunds = DB::table('refund_transactions')
            ->selectRaw("
                coalesce(sum(case when status = 'pending' then 1 else 0 end), 0) as pending_count,
                min(case when status = 'pending' then created_at end) as oldest_pending_at,
                coalesce(sum(case when status = 'manual_review' then 1 else 0 end), 0) as manual_count,
                coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as failed_count
            ")
            ->first();

        $payouts = DB::table('auction_seller_payouts')
            ->selectRaw("
                min(case when status = 'pending' then created_at end) as oldest_pending_at,
                coalesce(sum(case when status in ('pending', 'manual_review', 'on_hold') and destination_id is null then 1 else 0 end), 0) as missing_destination_count
            ")
            ->first();

        $disputes = DB::table('auction_disputes')
            ->where('status', 'open')
            ->selectRaw('count(*) as open_count, min(opened_at) as oldest_at')
            ->first();

        return [
            'payment_reviews' => ['count' => (int) $submissions->pending_count, 'oldest_at' => $submissions->oldest_at],
            'refunds' => [
                'pending_count' => (int) $refunds->pending_count,
                'oldest_pending_at' => $refunds->oldest_pending_at,
                'manual_count' => (int) $refunds->manual_count,
                'failed_count' => (int) $refunds->failed_count,
            ],
            'payouts' => [
                'oldest_pending_at' => $payouts->oldest_pending_at,
                'missing_destination_count' => (int) $payouts->missing_destination_count,
            ],
            'disputes' => ['open_count' => (int) $disputes->open_count, 'oldest_at' => $disputes->oldest_at],
        ];
    }

    /** @return array<int, object> */
    public function recentCompletedSettlements(): array
    {
        return DB::table('auction_settlements')
            ->join('auctions', 'auctions.id', '=', 'auction_settlements.auction_id')
            ->where('auction_settlements.status', 'completed')
            ->whereNotNull('auction_settlements.completed_at')
            ->orderByDesc('auction_settlements.completed_at')
            ->limit(self::RECENT_LIMIT)
            ->get([
                'auction_settlements.public_id',
                'auctions.public_id as auction_public_id',
                'auctions.title as auction_title',
                'auction_settlements.winning_amount_minor',
                'auction_settlements.platform_fee_minor',
                'auction_settlements.seller_net_amount_minor',
                'auction_settlements.currency_code',
                'auction_settlements.completed_at',
            ])
            ->all();
    }

    /** @return array<int, object> */
    public function largestUnpaidPayouts(): array
    {
        return DB::table('auction_seller_payouts')
            ->join('auctions', 'auctions.id', '=', 'auction_seller_payouts.auction_id')
            ->join('users', 'users.id', '=', 'auction_seller_payouts.seller_id')
            ->whereIn('auction_seller_payouts.status', ['pending', 'on_hold', 'processing', 'manual_review', 'failed'])
            ->orderByDesc('auction_seller_payouts.amount_minor')
            ->limit(self::RECENT_LIMIT)
            ->get([
                'auction_seller_payouts.public_id',
                'auctions.public_id as auction_public_id',
                'auctions.title as auction_title',
                'users.name as seller_name',
                'auction_seller_payouts.status',
                'auction_seller_payouts.amount_minor',
                'auction_seller_payouts.currency_code',
                'auction_seller_payouts.created_at',
            ])
            ->all();
    }

    /** @return array<int, object> */
    public function overdueWinnerPayments(): array
    {
        return DB::table('auction_settlements')
            ->join('auctions', 'auctions.id', '=', 'auction_settlements.auction_id')
            ->join('users', 'users.id', '=', 'auction_settlements.winner_id')
            ->where('auction_settlements.is_current', true)
            ->where('auction_settlements.status', 'payment_pending')
            ->whereNotNull('auction_settlements.payment_due_at')
            ->where('auction_settlements.payment_due_at', '<', now())
            ->orderBy('auction_settlements.payment_due_at')
            ->limit(self::RECENT_LIMIT)
            ->get([
                'auction_settlements.public_id',
                'auctions.public_id as auction_public_id',
                'auctions.title as auction_title',
                'users.name as winner_name',
                'auction_settlements.remaining_amount_minor',
                'auction_settlements.currency_code',
                'auction_settlements.payment_due_at',
            ])
            ->all();
    }

    /** @return array<int, object> */
    public function recentOpenDisputes(): array
    {
        return DB::table('auction_disputes')
            ->join('auctions', 'auctions.id', '=', 'auction_disputes.auction_id')
            ->where('auction_disputes.status', 'open')
            ->orderByDesc('auction_disputes.opened_at')
            ->limit(self::RECENT_LIMIT)
            ->get([
                'auction_disputes.public_id',
                'auctions.public_id as auction_public_id',
                'auctions.title as auction_title',
                'auction_disputes.reason',
                'auction_disputes.opened_at',
            ])
            ->all();
    }

    /** @return array<int, object> */
    public function attentionRefunds(): array
    {
        return DB::table('refund_transactions')
            ->join('auctions', 'auctions.id', '=', 'refund_transactions.auction_id')
            ->join('users', 'users.id', '=', 'refund_transactions.user_id')
            ->whereIn('refund_transactions.status', ['manual_review', 'failed'])
            ->orderByDesc('refund_transactions.created_at')
            ->limit(self::RECENT_LIMIT)
            ->get([
                'refund_transactions.public_id',
                'auctions.public_id as auction_public_id',
                'auctions.title as auction_title',
                'users.name as user_name',
                'refund_transactions.status',
                'refund_transactions.amount_minor',
                'refund_transactions.currency_code',
                'refund_transactions.created_at',
            ])
            ->all();
    }
}
