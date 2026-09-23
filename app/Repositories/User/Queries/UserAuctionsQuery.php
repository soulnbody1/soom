<?php

declare(strict_types=1);

namespace App\Repositories\User\Queries;

use App\Models\Auction\Auction;
use App\Models\User;
use App\Services\Market\MarketQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UserAuctionsQuery
{
    public const SCOPES = ['created', 'participated', 'won', 'lost'];

    private const LOST_STATUSES = ['completed', 'unsold', 'cancelled', 'defaulted'];

    private const COLUMNS = [
        'auctions.id',
        'auctions.public_id',
        'auctions.seller_id',
        'auctions.category_id',
        'auctions.currency_code',
        'auctions.title',
        'auctions.status',
        'auctions.starting_amount_minor',
        'auctions.reserve_amount_minor',
        'auctions.starts_at',
        'auctions.ends_at',
        'auctions.published_at',
        'auctions.cancelled_at',
        'auctions.completed_at',
        'auctions.created_at',
        'auctions.deleted_at',
    ];

    private const RELATIONS = [
        'category:id,name',
        'metric',
        'currentLeadingBid:id,auction_id,amount_minor,currency_code',
    ];

    public function __construct(private readonly MarketQuery $markets) {}

    public function paginate(User $user, string $scope, array $filters, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->baseQuery($user, $scope, $filters)->paginate($perPage)->withQueryString();

        $this->attachUserContext($user, collect($paginator->items()), $scope);

        return $paginator;
    }

    private function baseQuery(User $user, string $scope, array $filters): Builder
    {
        $query = Auction::withTrashed()
            ->select(self::COLUMNS)
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $inner, $value) => $inner->where('auctions.status', $value))
            ->when($filters['search'] ?? null, fn (Builder $inner, $value) => $inner->where('auctions.title', 'like', '%'.trim((string) $value).'%'));

        return match ($scope) {
            'created' => $query
                ->with('settlement')
                ->where('auctions.seller_id', $user->id)
                ->orderByDesc('auctions.id'),
            'won' => $query
                ->join('auction_settlements', 'auction_settlements.auction_id', '=', 'auctions.id')
                ->where('auction_settlements.winner_id', $user->id)
                ->where('auction_settlements.is_current', true)
                ->orderByDesc('auction_settlements.id'),
            'lost' => $this->participatedQuery($query, $user)
                ->whereIn('auctions.status', self::LOST_STATUSES)
                ->whereNotExists(fn ($sub) => $sub
                    ->select(DB::raw(1))
                    ->from('auction_settlements')
                    ->whereColumn('auction_settlements.auction_id', 'auctions.id')
                    ->where('auction_settlements.winner_id', $user->id)
                    ->where('auction_settlements.is_current', true)),
            default => $this->participatedQuery($query, $user),
        };
    }

    private function participatedQuery(Builder $query, User $user): Builder
    {
        return $query
            ->join('auction_participants', 'auction_participants.auction_id', '=', 'auctions.id')
            ->addSelect([
                'auction_participants.status as participant_status',
                'auction_participants.registered_at as participated_at',
            ])
            ->where('auction_participants.user_id', $user->id)
            ->orderByDesc('auction_participants.id');
    }

    private function attachUserContext(User $user, Collection $auctions, string $scope): void
    {
        if ($auctions->isEmpty() || $scope === 'created') {
            return;
        }

        $auctionIds = $auctions->pluck('id')->all();

        $bidStats = $this->markets->table('auction_bids')
            ->selectRaw('auction_id, COUNT(*) as bids_count, MAX(amount_minor) as highest_amount_minor')
            ->where('bidder_id', $user->id)
            ->whereIn('auction_id', $auctionIds)
            ->groupBy('auction_id')
            ->get()
            ->keyBy('auction_id');

        $settlements = $this->markets->table('auction_settlements')
            ->select(['auction_id', 'public_id', 'status', 'winning_amount_minor', 'amount_due_minor', 'amount_paid_minor', 'currency_code', 'payment_due_at'])
            ->where('winner_id', $user->id)
            ->where('is_current', true)
            ->whereIn('auction_id', $auctionIds)
            ->get()
            ->keyBy('auction_id');

        $deposits = $this->markets->table('auction_deposits')
            ->select(['auction_id', 'status', 'type', 'held_amount_minor', 'currency_code'])
            ->where('user_id', $user->id)
            ->where('type', 'bidder')
            ->whereIn('auction_id', $auctionIds)
            ->get()
            ->keyBy('auction_id');

        foreach ($auctions as $auction) {
            $stats = $bidStats->get($auction->id);
            $auction->setAttribute('user_bids_count', (int) ($stats->bids_count ?? 0));
            $highest = $stats?->highest_amount_minor;
            $auction->setAttribute('user_highest_bid_minor', $highest !== null ? (int) $highest : null);
            $auction->setAttribute('user_settlement', $settlements->get($auction->id));
            $auction->setAttribute('user_deposit', $deposits->get($auction->id));
        }
    }
}
