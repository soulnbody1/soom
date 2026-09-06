<?php

declare(strict_types=1);

namespace App\Jobs\Ad;

use App\Jobs\SendFcmNotification;
use App\Models\Ad;
use App\Models\User;
use App\Notifications\NewAdNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

final class SendAdNotificationChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly int $adId,
        private readonly array $userIds,
    ) {}

    public function handle(): void
    {
        $ad = Ad::find($this->adId);

        if ($ad === null || $this->userIds === []) {
            return;
        }

        $recipients = User::query()
            ->select('id', 'fcm_token')
            ->whereIn('id', $this->userIds)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new NewAdNotification($ad));

        foreach ($recipients as $recipient) {
            if (! $recipient->fcm_token) {
                continue;
            }

            SendFcmNotification::dispatch(
                $recipient->fcm_token,
                '📢 إعلان جديد',
                $ad->title,
                ['ad_id' => $ad->id, 'category_id' => $ad->category_id],
            );
        }
    }
}
