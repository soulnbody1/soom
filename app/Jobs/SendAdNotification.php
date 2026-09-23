<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Market\RunsInMarket;
use App\Jobs\Ad\SendAdNotificationChunk;
use App\Jobs\Concerns\HasMarketJobContext;
use App\Models\Ad;
use App\Repositories\Ad\Queries\AdNotificationAudienceQuery;
use App\Services\Ad\Support\CategoryTreeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdNotification implements RunsInMarket, ShouldQueue
{
    use Dispatchable, HasMarketJobContext, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public int $timeout = 60;

    public function __construct(
        public readonly int $adId,
        public readonly int $marketId,
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    public function handle(
        CategoryTreeResolver $categories,
        AdNotificationAudienceQuery $audience,
    ): void {
        $ad = Ad::query()->find($this->adId);
        if ($ad === null) {
            return;
        }

        $categoryIds = $categories->ancestorIds((int) $ad->category_id);
        $chunkSize = (int) config('ads.notifications.chunk_size');
        $threshold = (int) config('ads.notifications.interaction_threshold');

        $afterUserId = 0;

        while (true) {
            $userIds = $audience->idsAfter($ad, $categoryIds, $afterUserId, $chunkSize, $threshold);

            if ($userIds === []) {
                return;
            }

            SendAdNotificationChunk::dispatch((int) $ad->id, $this->marketId, $userIds);

            $afterUserId = (int) end($userIds);
        }
    }

    public function marketId(): int
    {
        return $this->marketId;
    }
}
