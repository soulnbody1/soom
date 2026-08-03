<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class AdminAuctionQuery
{
    private const RELATIONS = [
        'media',
        'category',
        'seller',
        'metric',
        'currentLeadingBid',
        'winningBid',
        'settlement',
        'configurationSnapshot',
        'disputes',
    ];

    private const SORTABLE = ['created_at', 'starts_at', 'ends_at'];

    /**
     * Paginate all auctions for admin dashboard with server-side filters.
     * Replaces ListAdminAuctionsAction query.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Auction::with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['category_id'] ?? null, fn (Builder $query, $categoryId) => $query->where('category_id', (int) $categoryId))
            ->when($filters['seller_id'] ?? null, fn (Builder $query, $sellerId) => $query->where('seller_id', (int) $sellerId))
            ->when($filters['q'] ?? null, function (Builder $query, string $q): void {
                $query->where(function (Builder $match) use ($q): void {
                    $match->where('title', 'like', '%'.$q.'%')
                        ->orWhere('public_id', $q);
                });
            })
            ->when($filters['starts_from'] ?? null, fn (Builder $query, string $from) => $query->where('starts_at', '>=', $from))
            ->when($filters['starts_to'] ?? null, fn (Builder $query, string $to) => $query->where('starts_at', '<=', $to))
            ->when($filters['ends_from'] ?? null, fn (Builder $query, string $from) => $query->where('ends_at', '>=', $from))
            ->when($filters['ends_to'] ?? null, fn (Builder $query, string $to) => $query->where('ends_at', '<=', $to))
            ->when($filters['currency'] ?? null, fn (Builder $query, string $currency) => $query->where('currency_code', strtoupper($currency)))
            ->when($filters['phase'] ?? null, fn (Builder $query, string $phase) => $this->applyPhase($query, $phase))
            ->when(($filters['overdue_payment'] ?? null) === true, fn (Builder $query) => $query
                ->where('status', AuctionStatus::PaymentPending->value)
                ->whereHas('settlement', fn (Builder $settlement) => $settlement
                    ->where('status', SettlementStatus::PaymentPending->value)
                    ->whereNotNull('payment_due_at')
                    ->where('payment_due_at', '<', Carbon::now())))
            ->when(($filters['overdue_handover'] ?? null) === true, fn (Builder $query) => $query
                ->where('status', AuctionStatus::HandoverPending->value)
                ->whereHas('settlement', fn (Builder $settlement) => $settlement
                    ->whereNull('handover_completed_at')
                    ->whereNotNull('handover_due_at')
                    ->where('handover_due_at', '<', Carbon::now())))
            ->when(($filters['has_dispute'] ?? null) === true, fn (Builder $query) => $query
                ->whereHas('disputes', fn (Builder $dispute) => $dispute->whereNull('resolved_at')))
            ->when(($filters['awaiting_seller_deposit'] ?? null) === true, fn (Builder $query) => $query
                ->where('status', AuctionStatus::AwaitingSellerDeposit->value))
            ->when(
                in_array($filters['sort'] ?? null, self::SORTABLE, true),
                fn (Builder $query) => $query->orderBy(
                    (string) $filters['sort'],
                    ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc'
                ),
                fn (Builder $query) => $query->latest('id')
            )
            ->paginate($perPage)
            ->withQueryString();
    }

    private function applyPhase(Builder $query, string $phase): Builder
    {
        $now = Carbon::now();

        return match ($phase) {
            'live' => $query->where('status', AuctionStatus::Live->value),
            'upcoming' => $query->whereIn('status', [
                AuctionStatus::Scheduled->value,
                AuctionStatus::AwaitingSellerDeposit->value,
                AuctionStatus::PendingReview->value,
            ])->where(fn (Builder $inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '>', $now)),
            'finished' => $query->whereIn('status', [
                AuctionStatus::Ended->value,
                AuctionStatus::SettlementPending->value,
                AuctionStatus::PaymentPending->value,
                AuctionStatus::HandoverPending->value,
                AuctionStatus::Completed->value,
                AuctionStatus::Unsold->value,
                AuctionStatus::Cancelled->value,
                AuctionStatus::Defaulted->value,
            ]),
            default => $query,
        };
    }
}
