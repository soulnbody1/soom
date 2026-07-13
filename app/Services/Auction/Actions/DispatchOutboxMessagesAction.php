<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\OutboxMessage;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Models\User;
use App\Notifications\AuctionOutboxNotification;
use App\Repositories\Auction\AuctionOutboxRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DispatchOutboxMessagesAction
{
    private const SUPPORTED_EVENTS = [
        'auction.status_changed',
        'auction.finalized',
        'auction.payment_approved',
        'auction.payment_rejected',
        'auction.refund_succeeded',
        'auction.cancelled',
    ];

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
                $this->dispatchNotification($message);
                $this->outbox->markAsProcessed($message);
                $count++;
            } catch (Throwable $exception) {
                $this->outbox->markAsFailed($message, $exception->getMessage());
            }
        }

        return $count;
    }

    private function dispatchNotification(OutboxMessage $message): void
    {
        if (! in_array($message->event_type, self::SUPPORTED_EVENTS, true)) {
            throw new \RuntimeException("Unsupported auction outbox event: {$message->event_type}");
        }

        if ($message->aggregate_type !== Auction::class) {
            throw new \RuntimeException("Unsupported auction outbox aggregate: {$message->aggregate_type}");
        }

        $auction = Auction::with(['seller', 'settlement.winner', 'participants.user'])
            ->findOrFail($message->aggregate_id);

        $recipients = $this->recipientsFor($message, $auction);
        if ($recipients->isEmpty()) {
            throw new \RuntimeException("No notification recipient found for {$message->event_type}.");
        }

        $data = $this->notificationData($message, $auction);

        $recipients->each(function (User $user) use ($message, $data): void {
            if ($this->alreadyNotified($user, (string) $message->event_id)) {
                return;
            }

            $user->notify(new AuctionOutboxNotification(
                (string) $message->event_id,
                $message->event_type,
                $data,
            ));
        });
    }

    private function recipientsFor(OutboxMessage $message, Auction $auction): Collection
    {
        return match ($message->event_type) {
            'auction.status_changed' => $this->statusChangedRecipients($message, $auction),
            'auction.finalized' => $this->users([
                $auction->seller,
                $this->winnerFor($message, $auction),
            ]),
            'auction.payment_approved',
            'auction.payment_rejected' => $this->users([$this->paymentSubmissionUser($message)]),
            'auction.refund_succeeded' => $this->users([$this->refundUser($message)]),
            'auction.cancelled' => $this->cancelledRecipients($auction),
            default => collect(),
        };
    }

    private function statusChangedRecipients(OutboxMessage $message, Auction $auction): Collection
    {
        $to = (string) (($message->payload ?? [])['to'] ?? '');

        if (! in_array($to, [
            AuctionStatus::Scheduled->value,
            AuctionStatus::Live->value,
            AuctionStatus::Ended->value,
        ], true)) {
            throw new \RuntimeException("Unsupported status_changed target: {$to}");
        }

        return $this->users([$auction->seller]);
    }

    private function cancelledRecipients(Auction $auction): Collection
    {
        return $this->users([
            $auction->seller,
            $auction->settlement?->winner,
            ...$auction->participants->map(fn ($participant) => $participant->user)->all(),
        ]);
    }

    /**
     * @param  array<int, User|null>  $users
     */
    private function users(array $users): Collection
    {
        return collect($users)
            ->filter(fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }

    private function winnerFor(OutboxMessage $message, Auction $auction): ?User
    {
        $winnerId = (int) (($message->payload ?? [])['winner_id'] ?? 0);

        if ($auction->settlement?->winner) {
            return $auction->settlement->winner;
        }

        return $winnerId > 0 ? User::find($winnerId) : null;
    }

    private function paymentSubmissionUser(OutboxMessage $message): ?User
    {
        $payload = $message->payload ?? [];
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($userId > 0) {
            return User::find($userId);
        }

        $submissionId = (int) ($payload['payment_submission_id'] ?? 0);
        if ($submissionId <= 0) {
            return null;
        }

        return PaymentSubmission::with('user')->find($submissionId)?->user;
    }

    private function refundUser(OutboxMessage $message): ?User
    {
        $payload = $message->payload ?? [];
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($userId > 0) {
            return User::find($userId);
        }

        $refundId = (int) ($payload['refund_transaction_id'] ?? 0);
        if ($refundId <= 0) {
            return null;
        }

        return RefundTransaction::with('user')->find($refundId)?->user;
    }

    private function notificationData(OutboxMessage $message, Auction $auction): array
    {
        return [
            'auction_id' => $auction->public_id,
            'title' => $auction->title,
            'message' => $this->messageText($message),
        ];
    }

    private function messageText(OutboxMessage $message): string
    {
        $payload = $message->payload ?? [];

        return match ($message->event_type) {
            'auction.status_changed' => match ((string) ($payload['to'] ?? '')) {
                AuctionStatus::Scheduled->value => 'Auction approved and scheduled.',
                AuctionStatus::Live->value => 'Auction has started.',
                AuctionStatus::Ended->value => 'Auction has ended.',
                default => 'Auction status changed.',
            },
            'auction.finalized' => 'Auction winner has been selected.',
            'auction.payment_approved' => 'Your auction payment was approved.',
            'auction.payment_rejected' => 'Your auction payment was rejected.',
            'auction.refund_succeeded' => 'Your auction refund was completed.',
            'auction.cancelled' => 'Auction was cancelled.',
            default => 'Auction update.',
        };
    }

    private function alreadyNotified(User $user, string $eventId): bool
    {
        return $user->notifications()
            ->where('type', AuctionOutboxNotification::class)
            ->where('data->event_id', $eventId)
            ->exists();
    }
}
