<?php

declare(strict_types=1);

namespace App\Repositories\User\Queries;

use App\Models\User;
use App\Services\Market\MarketQuery;
use App\Support\Market\MarketContext;
use Illuminate\Support\Facades\DB;

final class UserProfileOverviewQuery
{
    public function __construct(
        private readonly UserConversationsQuery $conversations,
        private readonly MarketQuery $markets,
        private readonly MarketContext $context,
    ) {}

    public function counts(User $user): array
    {
        $id = $user->id;
        $state = $this->context->state();

        return [
            'market' => $state->market === null ? 'all' : strtolower((string) $state->market->code),
            'ads' => $this->markets->table('ads')->where('user_id', $id)->whereNull('deleted_at')->count(),
            'favorites' => DB::table('favorites')->where('user_id', $id)
                ->whereExists(fn ($sub) => $sub->select(DB::raw(1))->fromSub($this->markets->table('ads'), 'scoped_ads')
                    ->whereColumn('scoped_ads.id', 'favorites.ad_id'))
                ->count(),
            'saved_ads' => $this->markets->table('user_ad_interactions')->where('user_id', $id)->where('action', 'save')->count(),
            'auctions_created' => $this->markets->table('auctions')->where('seller_id', $id)->whereNull('deleted_at')->count(),
            'auctions_participated' => $this->markets->table('auction_participants')->where('user_id', $id)->count(),
            'auctions_won' => $this->markets->table('auction_settlements')->where('winner_id', $id)->where('is_current', true)->count(),
            'bids' => $this->markets->table('auction_bids')->where('bidder_id', $id)->count(),
            'deposits' => $this->markets->table('auction_deposits')->where('user_id', $id)->count(),
            'payment_submissions' => $this->markets->table('payment_submissions')->where('user_id', $id)->count(),
            'refunds' => $this->markets->table('refund_transactions')->where('user_id', $id)->count(),
            'seller_payouts' => $this->markets->table('auction_seller_payouts')->where('seller_id', $id)->count(),
            'payout_destinations' => DB::table('payout_destinations')->where('user_id', $id)->whereNull('deleted_at')->count(),
            'conversations' => $this->conversations->countThreads($id),
            'unread_messages' => DB::table('messages')->where('receiver_id', $id)->where('is_read', false)->count(),
        ];
    }

    public function sessionTimestamps(User $user): array
    {
        return [
            'last_login_at' => DB::table('refresh_tokens')->where('user_id', $user->id)->max('updated_at'),
            'last_activity_at' => DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->max('last_used_at'),
        ];
    }
}
