<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Ad\SendAdNotificationChunk;
use App\Models\Ad;
use App\Repositories\Ad\Queries\AdNotificationAudienceQuery;
use App\Services\Ad\Support\CategoryTreeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public Ad $ad) {}

    public function handle(
        CategoryTreeResolver $categories,
        AdNotificationAudienceQuery $audience,
    ): void {
        $categoryIds = $categories->ancestorIds((int) $this->ad->category_id);
        $chunkSize = (int) config('ads.notifications.chunk_size');
        $threshold = (int) config('ads.notifications.interaction_threshold');

        $afterUserId = 0;

        while (true) {
            $userIds = $audience->idsAfter($this->ad, $categoryIds, $afterUserId, $chunkSize, $threshold);

            if ($userIds === []) {
                return;
            }

            SendAdNotificationChunk::dispatch((int) $this->ad->id, $userIds);

            $afterUserId = (int) end($userIds);
        }
    }
}
