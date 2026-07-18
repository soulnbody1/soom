<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Jobs\SendFcmNotification;
use App\Models\Auction\Auction;
use App\Models\User;
use App\Services\Auction\Support\AuctionNotificationCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendAuctionAnnouncementJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $auctionId,
        private readonly string $type,
        private readonly string $title,
        private readonly string $body,
    ) {}

    public function handle(): void
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
            ->where('allow_ad_notifications', true)
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->where('id', '!=', $auction->seller_id)
            ->chunkById(100, function ($users) use ($data): void {
                foreach ($users as $user) {
                    SendFcmNotification::dispatch($user->fcm_token, $this->title, $this->body, $data);
                }
            });
    }
}
