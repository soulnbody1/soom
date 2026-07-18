<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\ValueObjects\Money;
use App\Events\Auction\AuctionPublicAnnouncementEvent;
use App\Events\Auction\AuctionRealtimeEvent;
use App\Jobs\SendFcmNotification;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\OutboxMessage;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Models\User;
use App\Notifications\AuctionOutboxNotification;
use App\Repositories\Auction\AuctionOutboxRepository;
use App\Services\Auction\Support\AuctionNotificationCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class DispatchOutboxMessagesAction
{
    private const NOTIFICATION_LOCALE = 'ar';

    public function __construct(
        private readonly AuctionOutboxRepository $outbox,
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
        if (! self::supports($message->event_type)) {
            throw new \RuntimeException("Unsupported auction outbox event: {$message->event_type}");
        }

        if ($message->aggregate_type !== Auction::class) {
            throw new \RuntimeException("Unsupported auction outbox aggregate: {$message->aggregate_type}");
        }

        $auction = Auction::with([
            'seller',
            'settlement.winner',
            'participants.user',
            'currentLeadingBid',
            'metric',
            'category',
            'media',
        ])->findOrFail($message->aggregate_id);

        if (AuctionNotificationCatalog::hasPersonal($message->event_type)) {
            foreach ($this->personalDeliveries($message, $auction) as $delivery) {
                $this->deliverPersonal($message, $auction, $delivery);
            }
        }

        if (AuctionNotificationCatalog::hasRealtime($message->event_type)) {
            $this->broadcastRealtime($message, $auction);
        }

        if (AuctionNotificationCatalog::hasPublicAnnouncement($message->event_type)) {
            $this->broadcastAnnouncement($message, $auction);
        }
    }

    /**
     * Each delivery: [User $user, string $translationKey, array $params, string $screen, array $extra].
     *
     * @return array<int, array{user: User, key: string, params: array, screen: string, extra: array}>
     */
    private function personalDeliveries(OutboxMessage $message, Auction $auction): array
    {
        $payload = $message->payload ?? [];
        $params = ['auction' => (string) $auction->title];

        return match ($message->event_type) {
            'auction.status_changed' => $this->statusChangedDeliveries($payload, $auction, $params),
            'auction.bid_accepted' => $this->bidAcceptedDeliveries($payload, $auction, $params),
            'auction.participant_registered' => [
                $this->delivery(
                    $this->requiredUser((int) ($payload['user_id'] ?? 0), $message->event_type),
                    'participant_registered',
                    $params,
                    AuctionNotificationCatalog::SCREEN_AUCTION_PAYMENT,
                ),
            ],
            'auction.payment_submitted',
            'auction.payment_approved',
            'auction.payment_rejected' => $this->paymentDeliveries($message, $payload, $params),
            'auction.finalized' => $this->finalizedDeliveries($payload, $auction, $params),
            'auction.winner_defaulted' => $this->winnerDefaultedDeliveries($payload, $auction, $params),
            'auction.alternative_winner_selected' => $this->alternativeWinnerDeliveries($payload, $auction, $params),
            'auction.winner_deposit_forfeited' => $this->depositAmountDelivery($payload, 'winner_deposit_forfeited', $params, 'forfeited_amount_minor', $message->event_type),
            'auction.seller_deposit_forfeited' => $this->depositAmountDelivery($payload, 'seller_deposit_forfeited', $params, 'forfeited_amount', $message->event_type),
            'auction.seller_deposit_refund_planned' => $this->depositAmountDelivery($payload, 'seller_deposit_refund_planned', $params, 'amount_minor', $message->event_type, AuctionNotificationCatalog::SCREEN_AUCTION_REFUNDS),
            'auction.seller_deposit_manual_review' => $this->depositAmountDelivery($payload, 'seller_deposit_manual_review', $params, null, $message->event_type, AuctionNotificationCatalog::SCREEN_AUCTION_REFUNDS),
            'auction.non_winner_deposit_refund_planned',
            'auction.refund_succeeded',
            'auction.refund_manual_review' => $this->refundDeliveries($message, $payload, $params),
            'auction.seller_handover_confirmed' => $this->users([$auction->settlement?->winner])
                ->map(fn (User $user) => $this->delivery($user, 'seller_handover_confirmed', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS))
                ->all(),
            'auction.dispute_opened' => $this->users([$auction->seller, $auction->settlement?->winner])
                ->map(fn (User $user) => $this->delivery($user, 'dispute_opened', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DISPUTE))
                ->all(),
            'auction.dispute_resolved' => $this->users([$auction->seller, $auction->settlement?->winner])
                ->map(fn (User $user) => $this->delivery($user, 'dispute_resolved', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DISPUTE))
                ->all(),
            'auction.cancelled' => $this->users([
                $auction->seller,
                $auction->settlement?->winner,
                ...$auction->participants->map(fn ($participant) => $participant->user)->all(),
            ])
                ->map(fn (User $user) => $this->delivery($user, 'cancelled', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS))
                ->all(),
            default => [],
        };
    }

    private function statusChangedDeliveries(array $payload, Auction $auction, array $params): array
    {
        $to = (string) ($payload['to'] ?? '');
        $from = (string) ($payload['from'] ?? '');
        $reason = trim((string) ($payload['reason'] ?? ''));
        $sellerScreen = AuctionNotificationCatalog::SCREEN_SELLER_AUCTION;

        return match ($to) {
            AuctionStatus::PendingReview->value => [$this->sellerDelivery($auction, 'status.pending_review', $params, $sellerScreen)],
            AuctionStatus::Rejected->value => [$this->sellerDelivery(
                $auction,
                $reason !== '' ? 'status.rejected_with_reason' : 'status.rejected',
                [...$params, 'reason' => $reason],
                $sellerScreen,
            )],
            AuctionStatus::AwaitingSellerDeposit->value => [$this->sellerDelivery($auction, 'status.awaiting_seller_deposit', $params, AuctionNotificationCatalog::SCREEN_AUCTION_PAYMENT)],
            // The seller-deposit-approval path already notifies the seller
            // through auction.payment_approved, so only the direct approval
            // path (no deposit required) produces a personal notification.
            AuctionStatus::Scheduled->value => $from === AuctionStatus::PendingReview->value
                ? [$this->sellerDelivery($auction, 'status.scheduled', [...$params, 'starts_at' => $this->dateTime($auction->starts_at)], $sellerScreen)]
                : [],
            AuctionStatus::Live->value => [$this->sellerDelivery($auction, 'status.live', $params, $sellerScreen)],
            AuctionStatus::Ended->value => [$this->sellerDelivery($auction, 'status.ended', $params, $sellerScreen)],
            AuctionStatus::Unsold->value => [$this->sellerDelivery($auction, 'status.unsold', $params, $sellerScreen)],
            AuctionStatus::Completed->value => $this->users([$auction->seller, $auction->settlement?->winner])
                ->map(fn (User $user) => $this->delivery($user, 'status.completed', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS))
                ->all(),
            // settlement_pending -> handover_pending happens inside finalization
            // (fully covered by the winner deposit) and auction.finalized already
            // notifies both parties. Winners coming from payment_pending were
            // already notified via auction.payment_approved (winner_settlement).
            AuctionStatus::HandoverPending->value => $from === AuctionStatus::SettlementPending->value
                ? []
                : [
                    $this->sellerDelivery($auction, 'status.handover_pending_seller', $params, $sellerScreen),
                    ...($from !== AuctionStatus::PaymentPending->value && $auction->settlement?->winner
                        ? [$this->delivery($auction->settlement->winner, 'status.handover_pending_winner', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS)]
                        : []),
                ],
            default => throw new \RuntimeException("Unsupported status_changed target: {$to}"),
        };
    }

    private function bidAcceptedDeliveries(array $payload, Auction $auction, array $params): array
    {
        $bid = $this->bidFromPayload($payload);
        $bidderId = (int) ($payload['bidder_id'] ?? $bid?->bidder_id ?? 0);

        $previousLeaderId = array_key_exists('previous_leader_id', $payload)
            ? (int) ($payload['previous_leader_id'] ?? 0)
            : (int) ($bid?->previousBid?->bidder_id ?? 0);

        if ($previousLeaderId <= 0 || $previousLeaderId === $bidderId) {
            return [];
        }

        $previousLeader = User::find($previousLeaderId);
        if (! $previousLeader) {
            return [];
        }

        $amountMinor = (int) ($payload['amount_minor'] ?? $bid?->amount_minor ?? 0);
        $currency = (string) ($payload['currency_code'] ?? $bid?->currency_code ?? $auction->currency_code);

        return [
            $this->delivery(
                $previousLeader,
                'bid_outbid',
                [...$params, 'amount' => $this->money($amountMinor, $currency), 'currency' => $currency],
                AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS,
                [
                    'amount' => $this->money($amountMinor, $currency),
                    'currency' => $currency,
                    'ends_at' => $auction->ends_at?->toIso8601String(),
                ],
            ),
        ];
    }

    private function paymentDeliveries(OutboxMessage $message, array $payload, array $params): array
    {
        $user = $this->paymentSubmissionUser($payload);
        if (! $user) {
            throw new \RuntimeException("No notification recipient found for {$message->event_type}.");
        }

        $purpose = (string) ($payload['purpose'] ?? '');
        if (! in_array($purpose, ['seller_deposit', 'bidder_deposit', 'winner_settlement'], true)) {
            throw new \RuntimeException("Unsupported payment purpose for {$message->event_type}: {$purpose}");
        }

        $group = str_replace('auction.', '', $message->event_type);
        $reason = trim((string) ($payload['reason'] ?? ''));

        return [
            $this->delivery(
                $user,
                "{$group}.{$purpose}",
                [...$params, 'reason' => $reason !== '' ? $reason : '—'],
                AuctionNotificationCatalog::SCREEN_AUCTION_PAYMENT,
            ),
        ];
    }

    private function finalizedDeliveries(array $payload, Auction $auction, array $params): array
    {
        $winner = $this->winnerFor($payload, $auction);
        $settlement = $auction->settlement;

        $amountMinor = (int) ($settlement?->winning_amount_minor ?? 0);
        $currency = (string) ($settlement?->currency_code ?? $auction->currency_code);
        $amountParams = [
            ...$params,
            'amount' => $this->money($amountMinor, $currency),
            'currency' => $currency,
        ];

        $deliveries = [];

        if ($winner) {
            $dueAt = $settlement?->payment_due_at;
            $deliveries[] = $this->delivery(
                $winner,
                $dueAt ? 'finalized_winner' : 'finalized_winner_paid',
                [...$amountParams, 'deadline' => $this->dateTime($dueAt)],
                AuctionNotificationCatalog::SCREEN_AUCTION_PAYMENT,
                ['deadline' => $dueAt?->toIso8601String()],
            );
        }

        if ($auction->seller) {
            $deliveries[] = $this->delivery($auction->seller, 'finalized_seller', $amountParams, AuctionNotificationCatalog::SCREEN_SELLER_AUCTION);
        }

        if ($deliveries === []) {
            throw new \RuntimeException('No notification recipient found for auction.finalized.');
        }

        return $deliveries;
    }

    private function winnerDefaultedDeliveries(array $payload, Auction $auction, array $params): array
    {
        $defaulted = User::find((int) ($payload['defaulted_user_id'] ?? 0));

        return [
            ...($defaulted ? [$this->delivery($defaulted, 'winner_defaulted_winner', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS)] : []),
            ...($auction->seller ? [$this->delivery($auction->seller, 'winner_defaulted_seller', $params, AuctionNotificationCatalog::SCREEN_SELLER_AUCTION)] : []),
        ];
    }

    private function alternativeWinnerDeliveries(array $payload, Auction $auction, array $params): array
    {
        $winner = $this->requiredUser((int) ($payload['new_winner_id'] ?? 0), 'auction.alternative_winner_selected');
        $amountMinor = (int) ($payload['new_amount_minor'] ?? 0);
        $currency = (string) $auction->currency_code;

        return [
            $this->delivery(
                $winner,
                'alternative_winner_selected',
                [...$params, 'amount' => $this->money($amountMinor, $currency), 'currency' => $currency],
                AuctionNotificationCatalog::SCREEN_AUCTION_PAYMENT,
            ),
        ];
    }

    /**
     * Personal delivery for events keyed by a deposit_public_id payload entry.
     */
    private function depositAmountDelivery(
        array $payload,
        string $key,
        array $params,
        ?string $amountField,
        string $eventType,
        string $screen = AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS,
    ): array {
        $deposit = AuctionDeposit::where('public_id', (string) ($payload['deposit_public_id'] ?? ''))->first();
        if (! $deposit) {
            throw new \RuntimeException("No notification recipient found for {$eventType}.");
        }

        $user = $this->requiredUser((int) $deposit->user_id, $eventType);
        $currency = (string) $deposit->currency_code;

        if ($amountField !== null) {
            $params = [
                ...$params,
                'amount' => $this->money((int) ($payload[$amountField] ?? 0), $currency),
                'currency' => $currency,
            ];
        }

        return [$this->delivery($user, $key, $params, $screen)];
    }

    private function refundDeliveries(OutboxMessage $message, array $payload, array $params): array
    {
        $refund = $this->refundFromPayload($payload);
        if (! $refund) {
            throw new \RuntimeException("No notification recipient found for {$message->event_type}.");
        }

        $user = $this->requiredUser((int) $refund->user_id, $message->event_type);
        $currency = (string) $refund->currency_code;
        $key = str_replace('auction.', '', $message->event_type);

        return [
            $this->delivery(
                $user,
                $key,
                [...$params, 'amount' => $this->money((int) $refund->amount_minor, $currency), 'currency' => $currency],
                AuctionNotificationCatalog::SCREEN_AUCTION_REFUNDS,
            ),
        ];
    }

    private function deliverPersonal(OutboxMessage $message, Auction $auction, array $delivery): void
    {
        /** @var User $user */
        $user = $delivery['user'];

        if ($this->alreadyNotified($user, (string) $message->event_id)) {
            return;
        }

        $title = $this->text("{$delivery['key']}.title", $delivery['params']);
        $body = $this->text("{$delivery['key']}.body", $delivery['params']);

        $data = [
            'auction_id' => $auction->public_id,
            'auction_title' => $auction->title,
            'screen' => $delivery['screen'],
            'title' => $title,
            'message' => $body,
            ...$delivery['extra'],
        ];

        $user->notify(new AuctionOutboxNotification(
            (string) $message->event_id,
            $message->event_type,
            $data,
        ));

        if ($user->fcm_token) {
            SendFcmNotification::dispatch($user->fcm_token, $title, $body, [
                'event_type' => $message->event_type,
                'auction_id' => $auction->public_id,
                'screen' => $delivery['screen'],
            ]);
        }
    }

    private function broadcastRealtime(OutboxMessage $message, Auction $auction): void
    {
        $payload = $message->payload ?? [];

        $data = match ($message->event_type) {
            'auction.status_changed' => $this->statusChangedRealtimePayload($payload, $auction),
            'auction.bid_accepted' => $this->bidAcceptedRealtimePayload($payload, $auction),
            'auction.finalized' => [
                'auction_id' => $auction->public_id,
                'status' => $auction->status->value,
                'sold' => true,
                'amount' => $this->money((int) ($auction->settlement?->winning_amount_minor ?? 0), (string) $auction->currency_code),
                'currency' => (string) $auction->currency_code,
                'winner' => ['anonymous' => true],
            ],
            'auction.alternative_winner_selected' => [
                'auction_id' => $auction->public_id,
                'status' => $auction->status->value,
                'amount' => $this->money((int) ($payload['new_amount_minor'] ?? 0), (string) $auction->currency_code),
                'currency' => (string) $auction->currency_code,
                'winner' => ['anonymous' => true],
            ],
            'auction.cancelled' => [
                'auction_id' => $auction->public_id,
                'status' => AuctionStatus::Cancelled->value,
            ],
            default => null,
        };

        if ($data === null) {
            return;
        }

        broadcast(new AuctionRealtimeEvent($auction->public_id, $message->event_type, $data));
    }

    private function statusChangedRealtimePayload(array $payload, Auction $auction): ?array
    {
        $to = (string) ($payload['to'] ?? '');

        // Realtime updates only for transitions visible to auction subscribers.
        if (! in_array($to, [
            AuctionStatus::Live->value,
            AuctionStatus::Ended->value,
            AuctionStatus::Unsold->value,
            AuctionStatus::Completed->value,
        ], true)) {
            return null;
        }

        $leading = $auction->currentLeadingBid;

        return [
            'auction_id' => $auction->public_id,
            'status' => $to,
            'starts_at' => $auction->starts_at?->toIso8601String(),
            'ends_at' => $auction->ends_at?->toIso8601String(),
            'current_amount' => $leading
                ? $this->money((int) $leading->amount_minor, (string) $leading->currency_code)
                : $this->money((int) $auction->starting_amount_minor, (string) $auction->currency_code),
            'currency' => (string) $auction->currency_code,
            'bids_count' => (int) ($auction->metric?->bids_count ?? 0),
        ];
    }

    private function bidAcceptedRealtimePayload(array $payload, Auction $auction): array
    {
        $bid = $this->bidFromPayload($payload);
        $amountMinor = (int) ($payload['amount_minor'] ?? $bid?->amount_minor ?? 0);
        $currency = (string) ($payload['currency_code'] ?? $bid?->currency_code ?? $auction->currency_code);

        return [
            'auction_id' => $auction->public_id,
            'bid_id' => (string) ($payload['bid_public_id'] ?? $bid?->public_id ?? ''),
            'amount' => $this->money($amountMinor, $currency),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'sequence_number' => $bid?->sequence_number,
            'accepted_at' => $bid?->accepted_at?->toIso8601String(),
            'bidder' => ['anonymous' => true],
            'ends_at' => $auction->ends_at?->toIso8601String(),
            'extended' => (bool) ($payload['extended'] ?? false),
            'extension_count' => (int) $auction->extension_count,
            'bids_count' => (int) ($auction->metric?->bids_count ?? 0),
            'status' => $auction->status->value,
        ];
    }

    private function broadcastAnnouncement(OutboxMessage $message, Auction $auction): void
    {
        $payload = $message->payload ?? [];

        [$type, $extra] = match ($message->event_type) {
            'auction.status_changed' => match ((string) ($payload['to'] ?? '')) {
                AuctionStatus::Scheduled->value => ['published', []],
                AuctionStatus::Live->value => ['started', []],
                default => [null, []],
            },
            // Marketing announcement only when a publicly visible auction is
            // cancelled before it started.
            'auction.cancelled' => $auction->published_at && ! $auction->started_at
                ? ['cancelled', []]
                : [null, []],
            default => [null, []],
        };

        if ($type === null) {
            return;
        }

        $currency = (string) $auction->currency_code;
        $params = [
            'auction' => (string) $auction->title,
            'starts_at' => $this->dateTime($auction->starts_at),
            'amount' => $this->money((int) $auction->starting_amount_minor, $currency),
            'currency' => $currency,
        ];

        $primaryImage = $auction->media->firstWhere('is_primary', true) ?? $auction->media->first();

        broadcast(new AuctionPublicAnnouncementEvent($type, [
            'auction_id' => $auction->public_id,
            'title' => (string) $auction->title,
            'category' => $auction->category?->name,
            'image_url' => $primaryImage ? Storage::disk($primaryImage->disk)->url($primaryImage->path) : null,
            'starting_amount' => $this->money((int) $auction->starting_amount_minor, $currency),
            'currency' => $currency,
            'starts_at' => $auction->starts_at?->toIso8601String(),
            'ends_at' => $auction->ends_at?->toIso8601String(),
            'screen' => AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS,
            'announcement_title' => $this->text("announcements.{$type}.title", $params),
            'announcement_message' => $this->text("announcements.{$type}.body", $params),
            ...$extra,
        ]));
    }

    private function delivery(User $user, string $key, array $params, string $screen, array $extra = []): array
    {
        return ['user' => $user, 'key' => $key, 'params' => $params, 'screen' => $screen, 'extra' => $extra];
    }

    private function sellerDelivery(Auction $auction, string $key, array $params, string $screen): array
    {
        $seller = $auction->seller;
        if (! $seller instanceof User) {
            throw new \RuntimeException('No notification recipient found for auction.status_changed.');
        }

        return $this->delivery($seller, $key, $params, $screen);
    }

    private function text(string $key, array $params): string
    {
        return (string) Lang::get("auction.notifications.{$key}", $params, self::NOTIFICATION_LOCALE);
    }

    private function money(int $minor, string $currency): string
    {
        try {
            return Money::fromMinorUnits(max(0, $minor), $currency)->toDecimalString();
        } catch (\InvalidArgumentException) {
            return (string) $minor;
        }
    }

    private function dateTime(?\DateTimeInterface $value): string
    {
        if (! $value) {
            return '—';
        }

        return Carbon::parse($value)
            ->timezone(config('app.timezone'))
            ->format('Y-m-d H:i');
    }

    private function requiredUser(int $userId, string $eventType): User
    {
        $user = $userId > 0 ? User::find($userId) : null;

        if (! $user) {
            throw new \RuntimeException("No notification recipient found for {$eventType}.");
        }

        return $user;
    }

    /**
     * @param  array<int, User|null>  $users
     */
    private function users(array $users): \Illuminate\Support\Collection
    {
        return collect($users)
            ->filter(fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }

    private function winnerFor(array $payload, Auction $auction): ?User
    {
        if ($auction->settlement?->winner) {
            return $auction->settlement->winner;
        }

        $winnerId = (int) ($payload['winner_id'] ?? 0);

        return $winnerId > 0 ? User::find($winnerId) : null;
    }

    private function bidFromPayload(array $payload): ?AuctionBid
    {
        $publicId = (string) ($payload['bid_public_id'] ?? '');

        return $publicId !== ''
            ? AuctionBid::with('previousBid')->where('public_id', $publicId)->first()
            : null;
    }

    private function refundFromPayload(array $payload): ?RefundTransaction
    {
        $refundId = (int) ($payload['refund_transaction_id'] ?? 0);
        if ($refundId > 0) {
            return RefundTransaction::find($refundId);
        }

        $publicId = (string) ($payload['refund_public_id'] ?? '');

        return $publicId !== ''
            ? RefundTransaction::where('public_id', $publicId)->first()
            : null;
    }

    private function paymentSubmissionUser(array $payload): ?User
    {
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

    private function alreadyNotified(User $user, string $eventId): bool
    {
        return $user->notifications()
            ->where('type', AuctionOutboxNotification::class)
            ->where('data->event_id', $eventId)
            ->exists();
    }
}
