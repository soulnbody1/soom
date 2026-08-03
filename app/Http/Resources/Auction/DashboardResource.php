<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SellerPayoutStatus;
use Carbon\CarbonImmutable;

final class DashboardResource
{
    private const PAYOUT_UNPAID_STATUSES = ['pending', 'on_hold', 'processing', 'manual_review', 'failed'];

    private const REFUND_LIABILITY_STATUSES = ['pending', 'processing', 'manual_review'];

    public static function financials(array $revenue, array $forfeited, array $held, array $payouts, array $refunds, array $collections, string $currency): array
    {
        $revenueCurrent = $revenue['fee_cur'] + $forfeited['forfeited_cur'];
        $revenuePrevious = self::nullableSum($revenue['fee_prev'], $forfeited['forfeited_prev']);
        $refundLiability = self::statusTotals($refunds['statuses'], self::REFUND_LIABILITY_STATUSES);
        $heldTotal = $held['bidder_minor'] + $held['seller_minor'] + $held['winner_minor'] + $refundLiability['amount_minor'];

        return [
            'platform_revenue' => self::comparable($revenueCurrent, $revenuePrevious, $currency) + [
                'breakdown' => [
                    'commissions' => MoneyResource::make($revenue['fee_cur'], $currency),
                    'forfeited_deposits' => MoneyResource::make($forfeited['forfeited_cur'], $currency),
                ],
            ],
            'gross_sold_value' => self::comparable($revenue['gross_cur'], $revenue['gross_prev'], $currency) + [
                'sold_count' => $revenue['sold_cur'],
                'sold_count_previous' => $revenue['sold_prev'],
            ],
            'held_funds' => [
                'total' => MoneyResource::make($heldTotal, $currency),
                'bidder_deposits' => self::bucket($held['bidder_minor'], $held['bidder_count'], $currency),
                'seller_deposits' => self::bucket($held['seller_minor'], $held['seller_count'], $currency),
                'winner_funds' => self::bucket($held['winner_minor'], $held['winner_count'], $currency),
                'refunds_in_flight' => self::bucket($refundLiability['amount_minor'], $refundLiability['count'], $currency),
            ],
            'seller_payouts' => [
                'statuses' => self::statusMap($payouts['statuses'], SellerPayoutStatus::cases(), $currency),
                'unpaid_total' => MoneyResource::make(self::statusTotals($payouts['statuses'], self::PAYOUT_UNPAID_STATUSES)['amount_minor'], $currency),
                'unpaid_count' => self::statusTotals($payouts['statuses'], self::PAYOUT_UNPAID_STATUSES)['count'],
                'paid_in_period' => self::bucket($payouts['paid_cur_minor'], $payouts['paid_cur_count'], $currency),
                'paid_in_previous' => $payouts['paid_prev_minor'] === null
                    ? null
                    : self::bucket($payouts['paid_prev_minor'], (int) $payouts['paid_prev_count'], $currency),
                'paid_change_basis_points' => self::changeBasisPoints($payouts['paid_cur_minor'], $payouts['paid_prev_minor']),
            ],
            'refunds' => [
                'statuses' => self::statusMap($refunds['statuses'], RefundTransactionStatus::cases(), $currency),
                'liability_total' => MoneyResource::make($refundLiability['amount_minor'], $currency),
                'liability_count' => $refundLiability['count'],
                'succeeded_in_period' => self::bucket($refunds['succeeded_cur_minor'], $refunds['succeeded_cur_count'], $currency),
                'succeeded_in_previous' => $refunds['succeeded_prev_minor'] === null
                    ? null
                    : self::bucket($refunds['succeeded_prev_minor'], (int) $refunds['succeeded_prev_count'], $currency),
            ],
            'winner_collections' => [
                'pending_review' => self::bucket($collections['pending_minor'], $collections['pending_count'], $currency) + [
                    'oldest_submitted_at' => self::iso($collections['oldest_submitted_at']),
                ],
                'approved_in_period' => self::bucket($collections['approved_cur_minor'], $collections['approved_cur_count'], $currency) + [
                    'change_basis_points' => self::changeBasisPoints($collections['approved_cur_minor'], $collections['approved_prev_minor']),
                ],
                'rejected_in_period' => $collections['rejected_cur_count'],
                'awaiting_payment' => self::bucket($collections['awaiting_minor'], $collections['awaiting_count'], $currency),
                'overdue' => self::bucket($collections['overdue_minor'], $collections['overdue_count'], $currency),
                'due_soon_count' => $collections['due_soon_count'],
            ],
        ];
    }

    public static function auctions(array $statusCounts, array $lifecycle, array $ended, array $settlements, array $participation, array $topAuctions, array $revenue, string $currency): array
    {
        $counts = [];
        foreach (AuctionStatus::cases() as $status) {
            $counts[$status->value] = (int) ($statusCounts[$status->value] ?? 0);
        }

        $soldCount = max(1, $revenue['sold_cur']);

        return [
            'status_counts' => $counts,
            'lifecycle' => [
                'created' => ['current' => $lifecycle['created_cur'], 'previous' => $lifecycle['created_prev'], 'change_basis_points' => self::changeBasisPoints($lifecycle['created_cur'], $lifecycle['created_prev'])],
                'started' => ['current' => $lifecycle['started_cur'], 'previous' => $lifecycle['started_prev']],
                'completed' => ['current' => $lifecycle['completed_cur'], 'previous' => $lifecycle['completed_prev'], 'change_basis_points' => self::changeBasisPoints($lifecycle['completed_cur'], $lifecycle['completed_prev'])],
                'unsold' => ['current' => $lifecycle['unsold_cur'], 'previous' => $lifecycle['unsold_prev']],
                'cancelled' => ['current' => $lifecycle['cancelled_cur'], 'previous' => $lifecycle['cancelled_prev']],
            ],
            'rates' => [
                'ended_count' => $ended['ended_count'],
                'sold_count' => $ended['sold_count'],
                'sell_through_basis_points' => self::ratioBasisPoints($ended['sold_count'], $ended['ended_count']),
                'unsold_basis_points' => self::ratioBasisPoints($ended['unsold_count'], $ended['ended_count']),
                'cancellation_basis_points' => self::ratioBasisPoints($lifecycle['cancelled_cur'], $ended['ended_count'] + $lifecycle['cancelled_cur']),
                'avg_winning_amount' => $revenue['sold_cur'] > 0 ? MoneyResource::make(intdiv($revenue['gross_cur'], $soldCount), $currency) : null,
                'avg_platform_fee' => $revenue['sold_cur'] > 0 ? MoneyResource::make(intdiv($revenue['fee_cur'], $soldCount), $currency) : null,
                'avg_bids' => $ended['avg_bids'],
                'avg_bidders' => $ended['avg_bidders'],
                'extensions_total' => $ended['extensions_total'],
                'avg_uplift_pct' => $settlements['avg_uplift_pct'],
                'reserve_met_basis_points' => self::ratioBasisPoints($settlements['reserve_met_count'], $settlements['reserve_count']),
                'winner_defaults' => $settlements['winner_defaults'],
                'reassignments' => $settlements['reassignments'],
            ],
            'participation' => [
                'total_bids' => $participation['total_bids'],
                'unique_bidders' => $participation['unique_bidders'],
                'auctions_with_bids' => $participation['auctions_with_bids'],
                'registrations' => $participation['registrations'],
                'unique_participants' => $participation['unique_participants'],
                'qualified_participants' => $participation['qualified_participants'],
                'top_by_bids' => array_map(static fn (object $row) => [
                    'auction' => ['id' => $row->public_id, 'title' => $row->title, 'status' => $row->status],
                    'bid_count' => (int) $row->bid_count,
                    'bidder_count' => (int) $row->bidder_count,
                    'top_amount' => MoneyResource::make((int) $row->top_amount_minor, (string) ($row->currency_code ?: $currency)),
                ], $topAuctions),
            ],
        ];
    }

    public static function operations(array $queues, array $collections, array $payouts, array $refunds, array $statusCounts, array $recent, string $currency): array
    {
        $payoutBucket = static fn (string $status): array => self::bucket(
            (int) ($payouts['statuses'][$status]['amount_minor'] ?? 0),
            (int) ($payouts['statuses'][$status]['count'] ?? 0),
            $currency
        );
        $refundBucket = static fn (string $status): array => self::bucket(
            (int) ($refunds['statuses'][$status]['amount_minor'] ?? 0),
            (int) ($refunds['statuses'][$status]['count'] ?? 0),
            $currency
        );

        return [
            'queues' => [
                'payment_reviews' => ['count' => $queues['payment_reviews']['count'], 'oldest_at' => self::iso($queues['payment_reviews']['oldest_at'])],
                'overdue_winner_payments' => self::bucket($collections['overdue_minor'], $collections['overdue_count'], $currency),
                'due_soon_winner_payments' => ['count' => $collections['due_soon_count']],
                'refunds_pending' => $refundBucket('pending') + ['oldest_at' => self::iso($queues['refunds']['oldest_pending_at'])],
                'refunds_manual_review' => $refundBucket('manual_review'),
                'refunds_failed' => $refundBucket('failed'),
                'payouts_pending' => $payoutBucket('pending') + ['oldest_at' => self::iso($queues['payouts']['oldest_pending_at'])],
                'payouts_on_hold' => $payoutBucket('on_hold'),
                'payouts_manual_review' => $payoutBucket('manual_review'),
                'payouts_failed' => $payoutBucket('failed'),
                'payouts_missing_destination' => ['count' => $queues['payouts']['missing_destination_count']],
                'open_disputes' => ['count' => $queues['disputes']['open_count'], 'oldest_at' => self::iso($queues['disputes']['oldest_at'])],
                'auctions_pending_review' => ['count' => (int) ($statusCounts['pending_review'] ?? 0)],
                'auctions_settlement_pending' => ['count' => (int) ($statusCounts['settlement_pending'] ?? 0)],
            ],
            'recent' => [
                'completed_settlements' => array_map(static fn (object $row) => [
                    'id' => $row->public_id,
                    'auction' => ['id' => $row->auction_public_id, 'title' => $row->auction_title],
                    'winning_amount' => MoneyResource::make((int) $row->winning_amount_minor, (string) $row->currency_code),
                    'platform_fee' => MoneyResource::make((int) $row->platform_fee_minor, (string) $row->currency_code),
                    'seller_net_amount' => MoneyResource::make((int) $row->seller_net_amount_minor, (string) $row->currency_code),
                    'completed_at' => self::iso($row->completed_at),
                ], $recent['completed_settlements']),
                'largest_unpaid_payouts' => array_map(static fn (object $row) => [
                    'id' => $row->public_id,
                    'auction' => ['id' => $row->auction_public_id, 'title' => $row->auction_title],
                    'seller_name' => $row->seller_name,
                    'status' => $row->status,
                    'amount' => MoneyResource::make((int) $row->amount_minor, (string) $row->currency_code),
                    'created_at' => self::iso($row->created_at),
                ], $recent['largest_unpaid_payouts']),
                'overdue_winner_payments' => array_map(static fn (object $row) => [
                    'id' => $row->public_id,
                    'auction' => ['id' => $row->auction_public_id, 'title' => $row->auction_title],
                    'winner_name' => $row->winner_name,
                    'remaining_amount' => MoneyResource::make((int) $row->remaining_amount_minor, (string) $row->currency_code),
                    'payment_due_at' => self::iso($row->payment_due_at),
                ], $recent['overdue_winner_payments']),
                'open_disputes' => array_map(static fn (object $row) => [
                    'id' => $row->public_id,
                    'auction' => ['id' => $row->auction_public_id, 'title' => $row->auction_title],
                    'reason' => $row->reason,
                    'opened_at' => self::iso($row->opened_at),
                ], $recent['open_disputes']),
                'attention_refunds' => array_map(static fn (object $row) => [
                    'id' => $row->public_id,
                    'auction' => ['id' => $row->auction_public_id, 'title' => $row->auction_title],
                    'user_name' => $row->user_name,
                    'status' => $row->status,
                    'amount' => MoneyResource::make((int) $row->amount_minor, (string) $row->currency_code),
                    'created_at' => self::iso($row->created_at),
                ], $recent['attention_refunds']),
            ],
        ];
    }

    private static function comparable(int $current, ?int $previous, string $currency): array
    {
        return [
            'current' => MoneyResource::make($current, $currency),
            'previous' => $previous === null ? null : MoneyResource::make($previous, $currency),
            'change_basis_points' => self::changeBasisPoints($current, $previous),
        ];
    }

    private static function bucket(int $minor, int $count, string $currency): array
    {
        return ['count' => $count, 'amount' => MoneyResource::make($minor, $currency)];
    }

    private static function statusMap(array $statuses, array $cases, string $currency): array
    {
        $map = [];
        foreach ($cases as $case) {
            $row = $statuses[$case->value] ?? ['count' => 0, 'amount_minor' => 0];
            $map[$case->value] = self::bucket((int) $row['amount_minor'], (int) $row['count'], $currency);
        }

        return $map;
    }

    private static function statusTotals(array $statuses, array $keys): array
    {
        $count = 0;
        $amount = 0;
        foreach ($keys as $key) {
            $count += (int) ($statuses[$key]['count'] ?? 0);
            $amount += (int) ($statuses[$key]['amount_minor'] ?? 0);
        }

        return ['count' => $count, 'amount_minor' => $amount];
    }

    private static function changeBasisPoints(?int $current, ?int $previous): ?int
    {
        if ($previous === null || $previous === 0 || $current === null) {
            return null;
        }

        return self::scaledRatio($current - $previous, $previous);
    }

    private static function ratioBasisPoints(int $part, int $whole): ?int
    {
        return $whole > 0 ? self::scaledRatio($part, $whole) : null;
    }

    private static function scaledRatio(int $numerator, int $denominator): ?int
    {
        if ($denominator === 0) {
            return null;
        }

        $scaled = $numerator * 10000;
        $sign = ($scaled < 0) === ($denominator < 0) ? 1 : -1;
        $magnitude = intdiv(abs($scaled) + intdiv(abs($denominator), 2), abs($denominator));

        return $sign * $magnitude;
    }

    private static function nullableSum(?int $left, ?int $right): ?int
    {
        if ($left === null && $right === null) {
            return null;
        }

        return (int) $left + (int) $right;
    }

    private static function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp, config('app.timezone'))->toIso8601String();
    }
}
