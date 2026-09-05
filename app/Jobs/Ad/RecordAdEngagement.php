<?php

declare(strict_types=1);

namespace App\Jobs\Ad;

use App\Domain\Ad\Enums\AdInteractionAction;
use App\Models\AdView;
use App\Models\UserAdInteraction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecordAdEngagement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $adId,
        private readonly int $userId,
        private readonly AdInteractionAction $action,
        private readonly bool $recordView = false,
    ) {}

    public function handle(): void
    {
        if ($this->recordView) {
            AdView::insertOrIgnore([
                'ad_id' => $this->adId,
                'user_id' => $this->userId,
                'viewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        UserAdInteraction::insertOrIgnore([
            'ad_id' => $this->adId,
            'user_id' => $this->userId,
            'action' => $this->action->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
