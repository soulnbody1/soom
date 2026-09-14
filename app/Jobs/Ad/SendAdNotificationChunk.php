<?php

declare(strict_types=1);

namespace App\Jobs\Ad;

use App\Models\Ad;
use App\Models\User;
use App\Notifications\NewAdNotification;
use App\Services\Notification\PushDispatcher;
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

    public function handle(PushDispatcher $push): void
    {
        $ad = Ad::query()->find($this->adId, ['id', 'public_id', 'title', 'category_id']);

        if ($ad === null || $this->userIds === []) {
            return;
        }

        $pending = array_values(array_diff($this->userIds, $this->alreadyNotified((string) $ad->public_id)));

        if ($pending === []) {
            return;
        }

        $recipients = User::query()->whereKey($pending)->get(['id']);

        if ($recipients->isEmpty()) {
            return;
        }

        $recipientIds = $recipients->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        Notification::send($recipients, new NewAdNotification(
            (string) $ad->public_id,
            (string) $ad->title,
            (int) $ad->category_id,
            $this->unreadCountsAfterDelivery($recipientIds)
        ));

        $push->toUsers($recipientIds, '📢 إعلان جديد', (string) $ad->title, [
            'ad_id' => $ad->public_id,
            'category_id' => $ad->category_id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Ad notification chunk failed: '.$exception->getMessage(), [
            'ad_id' => $this->adId,
            'recipients' => count($this->userIds),
        ]);
    }

    private function alreadyNotified(string $adPublicId): array
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $this->userIds)
            ->where('type', NewAdNotification::class)
            ->where('data->ad_id', $adPublicId)
            ->pluck('notifiable_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function unreadCountsAfterDelivery(array $recipientIds): array
    {
        $existing = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $recipientIds)
            ->whereNull('read_at')
            ->selectRaw('notifiable_id, COUNT(*) as aggregate')
            ->groupBy('notifiable_id')
            ->pluck('aggregate', 'notifiable_id');

        $counts = [];

        foreach ($recipientIds as $id) {
            $counts[$id] = (int) ($existing[$id] ?? 0) + 1;
        }

        return $counts;
    }
}
