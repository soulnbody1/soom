<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ViewerAuctionQuery
{
    private const SORTABLE = [
        'latest' => ['id', 'desc'],
        'starting_soon' => ['starts_at', 'asc'],
        'ending_soon' => ['ends_at', 'asc'],
        'price_asc' => ['current_amount_minor', 'asc'],
        'price_desc' => ['current_amount_minor', 'desc'],
    ];

    private const LIVE_PHASE_STATUSES = [AuctionStatus::Live];

    private const UPCOMING_PHASE_STATUSES = [AuctionStatus::Scheduled];

    private const FINISHED_PHASE_STATUSES = [
        AuctionStatus::Ended,
        AuctionStatus::SettlementPending,
        AuctionStatus::PaymentPending,
        AuctionStatus::HandoverPending,
        AuctionStatus::Completed,
        AuctionStatus::Unsold,
    ];

    public function paginate(array $filters, ?int $viewerId, int $perPage): LengthAwarePaginator
    {
        return $this->applySort(
            $this->applyFilters(Auction::query()->public(), $filters),
            $filters['sort'] ?? null
        )
            ->with($this->relations($viewerId))
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateForSeller(int $sellerId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->applySort(
            $this->applyFilters(Auction::query()->where('seller_id', $sellerId), $filters),
            $filters['sort'] ?? null
        )
            ->with($this->relations($sellerId))
            ->paginate($perPage)
            ->withQueryString();
    }

    public function loadDetails(Auction $auction, ?int $viewerId): Auction
    {
        return $auction->load($this->relations($viewerId));
    }

    public function relations(?int $viewerId): array
    {
        $relations = [
            'market:id,code,web_host',
            'media',
            'category',
            'country',
            'state',
            'city',
            'metric',
            'currentLeadingBid',
            'configurationSnapshot',
            'settlement',
        ];

        if ($viewerId === null) {
            return $relations;
        }

        // Every viewer-scoped relation is filtered to the viewer, so the seller's
        // own deposit arrives through `deposits` when the viewer is the seller —
        // there is no separate `sellerDeposit` branch to load and throw away.
        return array_merge($relations, [
            'bids' => fn ($query) => $query->where('bidder_id', $viewerId)->orderBy('sequence_number'),
            'deposits' => fn ($query) => $query
                ->where('user_id', $viewerId)
                ->with([
                    'refunds',
                    'paymentSubmissions.paymentMethod',
                    'paymentSubmissions.transaction.refunds' => fn ($refunds) => $refunds->where('user_id', $viewerId),
                ]),
            'settlement.sellerPayout' => fn ($query) => $query->where('seller_id', $viewerId),
            'refunds' => fn ($query) => $query->where('user_id', $viewerId),
        ]);
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['category_id'] ?? null, fn (Builder $q, $value) => $q->where('category_id', $value))
            ->when($filters['currency'] ?? null, fn (Builder $q, $value) => $q->where('currency_code', strtoupper((string) $value)))
            ->when($filters['status'] ?? null, fn (Builder $q, $value) => $q->where('status', $value))
            ->when($filters['phase'] ?? null, fn (Builder $q, $value) => $q->whereIn('status', $this->phaseStatuses((string) $value)))
            ->when($filters['search'] ?? null, function (Builder $q, $value): void {
                $term = trim((string) $value);
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('title', 'like', '%'.$term.'%')
                        ->orWhere('public_id', $term);
                });
            });
    }

    private function applySort(Builder $query, ?string $sort): Builder
    {
        [$column, $direction] = self::SORTABLE[$sort] ?? self::SORTABLE['latest'];

        if ($column === 'current_amount_minor') {
            return $query
                ->leftJoin('auction_bids as leading_bid', 'leading_bid.id', '=', 'auctions.current_leading_bid_id')
                ->orderByRaw('coalesce(leading_bid.amount_minor, auctions.starting_amount_minor) '.$direction)
                ->select('auctions.*');
        }

        return $query->orderBy($column, $direction);
    }

    private function phaseStatuses(string $phase): array
    {
        $statuses = match ($phase) {
            'live' => self::LIVE_PHASE_STATUSES,
            'upcoming' => self::UPCOMING_PHASE_STATUSES,
            'finished' => self::FINISHED_PHASE_STATUSES,
            default => [],
        };

        return array_map(fn (AuctionStatus $status) => $status->value, $statuses);
    }
}
