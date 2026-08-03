<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class MyParticipationQuery
{
    private const SORTABLE = [
        'latest' => ['id', 'desc'],
        'starting_soon' => ['starts_at', 'asc'],
        'ending_soon' => ['ends_at', 'asc'],
    ];

    private const ACTIVE_STATUSES = [
        AuctionStatus::Scheduled,
        AuctionStatus::Live,
    ];

    private const SETTLING_STATUSES = [
        AuctionStatus::Ended,
        AuctionStatus::SettlementPending,
        AuctionStatus::PaymentPending,
        AuctionStatus::HandoverPending,
    ];

    public function __construct(
        private readonly ViewerAuctionQuery $relations,
    ) {}

    public function paginate(int $viewerId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Auction::query()
            ->whereHas('participants', fn (Builder $q) => $q->where('user_id', $viewerId))
            ->with($this->relations->relations($viewerId));

        $this->applyFilter($query, (string) ($filters['filter'] ?? 'all'), $viewerId);

        $query
            ->when($filters['status'] ?? null, fn (Builder $q, $value) => $q->where('status', $value))
            ->when($filters['search'] ?? null, function (Builder $q, $value): void {
                $term = trim((string) $value);
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('title', 'like', '%'.$term.'%')->orWhere('public_id', $term);
                });
            });

        [$column, $direction] = self::SORTABLE[$filters['sort'] ?? 'latest'] ?? self::SORTABLE['latest'];

        return $query->orderBy($column, $direction)->paginate($perPage)->withQueryString();
    }

    private function applyFilter(Builder $query, string $filter, int $viewerId): void
    {
        match ($filter) {
            'active' => $query->whereIn('status', $this->values(self::ACTIVE_STATUSES)),
            'won' => $query->whereHas('settlements', fn (Builder $q) => $q
                ->where('current_marker', 1)
                ->where('winner_id', $viewerId)),
            'lost' => $query
                ->whereIn('status', $this->values([
                    ...self::SETTLING_STATUSES,
                    AuctionStatus::Completed,
                    AuctionStatus::Unsold,
                    AuctionStatus::Defaulted,
                ]))
                ->whereDoesntHave('settlements', fn (Builder $q) => $q
                    ->where('current_marker', 1)
                    ->where('winner_id', $viewerId)),
            'pending_payment' => $query->whereHas('settlements', fn (Builder $q) => $q
                ->where('current_marker', 1)
                ->where('winner_id', $viewerId)
                ->where('status', SettlementStatus::PaymentPending->value)),
            'handover' => $query->whereHas('settlements', fn (Builder $q) => $q
                ->where('current_marker', 1)
                ->where('winner_id', $viewerId)
                ->whereIn('status', [SettlementStatus::Paid->value, SettlementStatus::HandoverPending->value])),
            'refunds' => $query->whereHas('deposits.paymentSubmissions.transaction.refunds', fn (Builder $q) => $q
                ->where('user_id', $viewerId)),
            default => null,
        };
    }

    private function values(array $statuses): array
    {
        return array_map(static fn (AuctionStatus $status): string => $status->value, $statuses);
    }
}
