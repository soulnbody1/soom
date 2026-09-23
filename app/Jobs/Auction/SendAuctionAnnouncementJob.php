<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Contracts\Market\RunsInMarket;
use App\Jobs\Concerns\HasMarketJobContext;
use App\Models\Auction\Auction;
use App\Models\User;
use App\Services\Auction\Support\AuctionNotificationCatalog;
use App\Services\Notification\PushDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class SendAuctionAnnouncementJob implements RunsInMarket, ShouldQueue
{
    use HasMarketJobContext, Queueable;

    public function __construct(
        private readonly int $auctionId,
        private readonly int $marketId,
        private readonly string $type,
        private readonly string $title,
        private readonly string $body,
    ) {}

    public function marketId(): int
    {
        return $this->marketId;
    }

    public function handle(PushDispatcher $push): void
    {
        $auction = Auction::with(['media', 'market:id,code,web_host'])->find($this->auctionId);

        if (! $auction || ! $auction->status->isPubliclyVisible()) {
            return;
        }

        $data = [
            'type' => $this->type,
            'auction_id' => (string) $auction->public_id,
            'market_code' => strtolower((string) $auction->market?->code),
            'url' => $auction->market?->webUrl('auctions/'.$auction->public_id),
            'screen' => AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS,
            'starting_amount' => (string) $auction->starting_amount_minor,
            'currency' => (string) $auction->currency_code,
            'starts_at' => (string) $auction->starts_at?->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ];

        User::query()
            ->select('id')
            ->where('allow_ad_notifications', true)
            ->where('id', '!=', $auction->seller_id)
            ->whereExists(fn (Builder $query) => $query
                ->select(DB::raw(1))
                ->from('device_tokens')
                ->whereColumn('device_tokens.user_id', 'users.id'))
            ->chunkById(100, function ($users) use ($push, $data): void {
                $push->toUsersInMarket(
                    $users->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                    $this->marketId,
                    $this->title,
                    $this->body,
                    $data
                );
            });
    }
}
