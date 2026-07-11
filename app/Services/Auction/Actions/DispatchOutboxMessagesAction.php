<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Events\Auction\AuctionOutboxEvent;
use App\Repositories\Auction\AuctionOutboxRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

final class DispatchOutboxMessagesAction
{
    public function __construct(
        private readonly AuctionOutboxRepository $outbox,
    ) {}

    public function execute(int $limit = 100): int
    {
        $count = 0;
        $worker = (string) Str::ulid();

        while ($count < $limit) {
            $message = DB::transaction(fn () => $this->outbox->leaseNextPending($worker));

            if (! $message) {
                break;
            }

            try {
                $responses = Event::dispatch(new AuctionOutboxEvent(
                    eventId: $message->event_id ?: $message->public_id,
                    topic: $message->topic,
                    eventType: $message->event_type,
                    aggregateType: $message->aggregate_type,
                    aggregateId: $message->aggregate_id,
                    payload: $message->payload ?? [],
                ));

                if (! in_array(true, $responses, true)) {
                    throw new \RuntimeException('No outbox consumer handled the message.');
                }

                $this->outbox->markAsPublished($message);
                $count++;
            } catch (Throwable $exception) {
                $this->outbox->markAsFailed($message, $exception->getMessage());
            }
        }

        return $count;
    }
}
