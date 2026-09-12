<?php

declare(strict_types=1);

namespace App\Jobs\Ad;

use App\Jobs\SendFcmNotification;
use App\Models\Ad;
use App\Models\User;
use App\Notifications\NewAdNotification;
use App\Services\Notification\DeviceTokenRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class SendAdNotificationChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public int $timeout = 60;

    public function __construct(
        public readonly int $adId,
        public readonly array $userIds,
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    public function handle(DeviceTokenRegistry $devices): void
    {
        $ad = Ad::find($this->adId);

        if ($ad === null || $this->userIds === []) {
            return;
        }

        $pending = array_values(array_diff($this->userIds, $this->alreadyNotified()));

        if ($pending === []) {
            return;
        }

        $recipients = User::query()->whereKey($pending)->get(['id']);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new NewAdNotification($ad));

        $this->push($ad, $devices->tokensForMany($recipients->pluck('id')->all()));
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Ad notification chunk failed: '.$exception->getMessage(), [
            'ad_id' => $this->adId,
            'recipients' => count($this->userIds),
        ]);
    }

    /**
     * @return list<int>
     */
    private function alreadyNotified(): array
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $this->userIds)
            ->where('type', NewAdNotification::class)
            ->where('data->ad_id', $this->adId)
            ->pluck('notifiable_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, list<string>>  $tokensByUser
     */
    private function push(Ad $ad, array $tokensByUser): void
    {
        foreach ($tokensByUser as $tokens) {
            foreach ($tokens as $token) {
                SendFcmNotification::dispatch(
                    $token,
                    '📢 إعلان جديد',
                    $ad->title,
                    ['ad_id' => $ad->id, 'category_id' => $ad->category_id],
                );
            }
        }
    }
}
