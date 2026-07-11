<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\OutboxStatus;
use App\Events\Auction\AuctionOutboxEvent;
use App\Models\Auction\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

final class DispatchOutboxMessagesAction
{
    public function execute(int $limit = 100): int
    {
        $count = 0;
        $worker = (string) Str::ulid();

        while ($count < $limit) {
            $message = $this->lease($worker);

            if (! $message) {
                break;
            }

            try {
                Event::dispatch(new AuctionOutboxEvent(
                    eventId: $message->event_id ?: $message->public_id,
                    topic: $message->topic,
                    eventType: $message->event_type,
                    aggregateType: $message->aggregate_type,
                    aggregateId: $message->aggregate_id,
                    payload: $message->payload ?? [],
                ));

                $message->forceFill([
                    'status' => OutboxStatus::Published,
                    'processed_at' => Carbon::now(),
                    'published_at' => Carbon::now(),
                    'locked_at' => null,
                    'locked_by' => null,
                    'last_error' => null,
                ])->save();

                $count++;
            } catch (Throwable $exception) {
                $message->forceFill([
                    'status' => OutboxStatus::Failed,
                    'failed_at' => Carbon::now(),
                    'locked_at' => null,
                    'locked_by' => null,
                    'last_error' => $exception->getMessage(),
                ])->save();
            }
        }

        return $count;
    }

    private function lease(string $worker): ?OutboxMessage
    {
        return DB::transaction(function () use ($worker): ?OutboxMessage {
            $message = OutboxMessage::where('status', OutboxStatus::Pending->value)
                ->where('available_at', '<=', Carbon::now())
                ->where(function ($query): void {
                    $query->whereNull('locked_at')
                        ->orWhere('locked_at', '<=', Carbon::now()->subMinutes(5));
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $message) {
                return null;
            }

            $message->forceFill([
                'event_id' => $message->event_id ?: $message->public_id,
                'locked_at' => Carbon::now(),
                'locked_by' => $worker,
                'attempts' => $message->attempts + 1,
            ])->save();

            return $message->refresh();
        });
    }
}
