<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Domain\Auction\Enums\OutboxStatus;
use App\Models\Auction\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class DispatchOutboxMessagesAction
{
    public function execute(int $limit = 100): int
    {
        $count = 0;

        OutboxMessage::where('status', OutboxStatus::Pending->value)
            ->where('available_at', '<=', Carbon::now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (OutboxMessage $message) use (&$count): void {
                try {
                    Log::info('Publishing auction outbox message.', [
                        'public_id' => $message->public_id,
                        'event_type' => $message->event_type,
                        'payload' => $message->payload,
                    ]);

                    $message->forceFill([
                        'status' => OutboxStatus::Published,
                        'published_at' => Carbon::now(),
                        'attempts' => $message->attempts + 1,
                    ])->save();

                    $count++;
                } catch (\Throwable $exception) {
                    $message->forceFill([
                        'status' => OutboxStatus::Failed,
                        'attempts' => $message->attempts + 1,
                        'last_error' => $exception->getMessage(),
                    ])->save();
                }
            });

        return $count;
    }
}
