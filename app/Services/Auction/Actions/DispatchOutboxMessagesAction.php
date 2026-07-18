<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\AuctionOutboxRepository;
use App\Services\Auction\Notifications\AuctionOutboxNotifier;
use App\Services\Auction\Support\AuctionNotificationCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DispatchOutboxMessagesAction
{
    public function __construct(
        private readonly AuctionOutboxRepository $outbox,
        private readonly AuctionOutboxNotifier $notifier,
    ) {}

    public static function supports(string $eventType): bool
    {
        return AuctionNotificationCatalog::supports($eventType);
    }

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
                $this->notifier->notify($message);
                $this->outbox->markAsProcessed($message);
                $count++;
            } catch (Throwable $exception) {
                $this->outbox->markAsFailed($message, $exception->getMessage());
            }
        }

        return $count;
    }
}
