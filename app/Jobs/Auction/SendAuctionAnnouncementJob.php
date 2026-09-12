<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Models\Auction\Auction;
use App\Models\User;
use App\Services\Auction\Support\AuctionNotificationCatalog;
use App\Services\Notification\PushDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class SendAuctionAnnouncementJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $auctionId,
        private readonly string $type,
        private readonly string $title,
        private readonly string $body,
    ) {}

    public function handle(PushDispatcher $push): void
    {
        $auction = Auction::with('media')->find($this->auctionId);

        if (! $auction || ! $auction->status->isPubliclyVisible()) {
            return;
        }

        $data = [
            'type' => $this->type,
            'auction_id' => (string) $auction->public_id,
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
                $push->toUsers(
                    $users->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                    $this->title,
                    $this->body,
                    $data
                );
            });
    }
}
