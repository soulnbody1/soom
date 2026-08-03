<?php

declare(strict_types=1);

namespace App\Services\Auction\Notifications;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\OutboxMessage;
use App\Models\User;
use App\Services\Auction\Support\AuctionNotificationCatalog;
use Illuminate\Support\Collection;

final class PersonalDeliveryResolver
{
    public function __construct(
        private readonly OutboxPayloadResolver $payloads,
        private readonly NotificationValueFormatter $format,
    ) {}

    /**
     * @return array<int, array{user: User, key: string, params: array, screen: string, extra: array}>
     */
    public function resolve(OutboxMessage $message, Auction $auction): array
    {
        $payload = $message->payload ?? [];
        $params = ['auction' => (string) $auction->title];

        return match ($message->event_type) {
            'auction.status_changed' => $this->statusChanged($payload, $auction, $params),
            'auction.bid_accepted' => $this->bidAccepted($payload, $auction, $params),
            'auction.participant_registered' => [
                $this->delivery(
                    $this->payloads->requiredUser((int) ($payload['user_id'] ?? 0), $message->event_type),
                    'participant_registered',
                    $params,
                    AuctionNotificationCatalog::SCREEN_AUCTION_PAYMENT,
                ),
            ],
            'auction.payment_submitted',
            'auction.payment_approved',
            'auction.payment_rejected' => $this->payment($message, $payload, $params),
            'auction.finalized' => $this->finalized($payload, $auction, $params),
            'auction.bidder_lost' => $this->bidderLost($payload, $auction, $params, 'bidder_lost'),
            'auction.unsold_bidders' => $this->bidderLost($payload, $auction, $params, 'unsold_bidder'),
            'auction.winner_payment_reminder' => $this->winnerPaymentReminder($payload, $auction, $params),
            'auction.handover_reminder' => $this->handoverReminder($payload, $auction, $params),
            'auction.seller_deposit_expired' => [
                $this->sellerDelivery($auction, 'seller_deposit_expired', $params, AuctionNotificationCatalog::SCREEN_SELLER_AUCTION),
            ],
            'auction.winner_defaulted' => $this->winnerDefaulted($payload, $auction, $params),
            'auction.alternative_winner_selected' => $this->alternativeWinner($payload, $auction, $params),
            'auction.winner_deposit_forfeited' => $this->depositEvent($payload, 'winner_deposit_forfeited', $params, 'forfeited_amount_minor', $message->event_type),
            'auction.seller_deposit_forfeited' => $this->depositEvent($payload, 'seller_deposit_forfeited', $params, 'forfeited_amount', $message->event_type),
            'auction.seller_deposit_partially_forfeited' => $this->depositEvent($payload, 'seller_deposit_partially_forfeited', $params, 'forfeited_amount', $message->event_type),
            'auction.seller_deposit_refund_planned' => $this->depositEvent($payload, 'seller_deposit_refund_planned', $params, 'amount_minor', $message->event_type, AuctionNotificationCatalog::SCREEN_AUCTION_REFUNDS),
            'auction.seller_deposit_manual_review' => $this->depositEvent($payload, 'seller_deposit_manual_review', $params, null, $message->event_type, AuctionNotificationCatalog::SCREEN_AUCTION_REFUNDS),
            'auction.non_winner_deposit_refund_planned',
            'auction.refund_succeeded',
            'auction.refund_manual_review' => $this->refund($message, $payload, $params),
            'auction.seller_handover_confirmed' => $this->users([$auction->settlement?->winner])
                ->map(fn (User $user) => $this->delivery($user, 'seller_handover_confirmed', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS))
                ->all(),
            'auction.dispute_opened' => $this->users([$auction->seller, $auction->settlement?->winner])
                ->map(fn (User $user) => $this->delivery($user, 'dispute_opened', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DISPUTE))
                ->all(),
            'auction.dispute_resolved' => $this->users([$auction->seller, $auction->settlement?->winner])
                ->map(fn (User $user) => $this->delivery($user, 'dispute_resolved', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DISPUTE))
                ->all(),
            'auction.seller_payout_created',
            'auction.seller_payout_on_hold',
            'auction.seller_payout_processing',
            'auction.seller_payout_paid',
            'auction.seller_payout_failed',
            'auction.seller_payout_manual_review' => $this->sellerPayout($message, $payload, $params),
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

    private function statusChanged(array $payload, Auction $auction, array $params): array
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
            // Deposit-approval path is covered by auction.payment_approved.
            AuctionStatus::Scheduled->value => $from === AuctionStatus::PendingReview->value
                ? [$this->sellerDelivery($auction, 'status.scheduled', [...$params, 'starts_at' => $this->format->dateTime($auction->starts_at)], $sellerScreen)]
                : [],
            AuctionStatus::Live->value => [$this->sellerDelivery($auction, 'status.live', $params, $sellerScreen)],
            AuctionStatus::Ended->value => [$this->sellerDelivery($auction, 'status.ended', $params, $sellerScreen)],
            AuctionStatus::Unsold->value => [$this->sellerDelivery($auction, 'status.unsold', $params, $sellerScreen)],
            AuctionStatus::Completed->value => $this->users([$auction->seller, $auction->settlement?->winner])
                ->map(fn (User $user) => $this->delivery($user, 'status.completed', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS))
                ->all(),
            // settlement_pending -> handover_pending is covered by auction.finalized;
            // winners arriving from payment_pending by auction.payment_approved.
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

    private function bidAccepted(array $payload, Auction $auction, array $params): array
    {
        $bid = $this->payloads->bid($payload);
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
        $amount = $this->format->money($amountMinor, $currency);

        return [
            $this->delivery(
                $previousLeader,
                'bid_outbid',
                [...$params, 'amount' => $amount, 'currency' => $currency],
                AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS,
                [
                    'amount' => $amount,
                    'currency' => $currency,
                    'ends_at' => $auction->ends_at?->toIso8601String(),
                ],
            ),
        ];
    }

    private function payment(OutboxMessage $message, array $payload, array $params): array
    {
        $user = $this->payloads->paymentSubmissionUser($payload);
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

    private function finalized(array $payload, Auction $auction, array $params): array
    {
        $winner = $this->payloads->winner($payload, $auction);
        $settlement = $auction->settlement;

        $amountMinor = (int) ($settlement?->winning_amount_minor ?? 0);
        $currency = (string) ($settlement?->currency_code ?? $auction->currency_code);
        $amountParams = [
            ...$params,
            'amount' => $this->format->money($amountMinor, $currency),
            'currency' => $currency,
        ];

        $deliveries = [];

        if ($winner) {
            $dueAt = $settlement?->payment_due_at;
            $deliveries[] = $this->delivery(
                $winner,
                $dueAt ? 'finalized_winner' : 'finalized_winner_paid',
                [...$amountParams, 'deadline' => $this->format->dateTime($dueAt)],
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

    private function bidderLost(array $payload, Auction $auction, array $params, string $group): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($payload['bidder_ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));

        if ($userIds === []) {
            return [];
        }

        $deposits = AuctionDeposit::where('auction_id', $auction->id)
            ->where('type', 'bidder')
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $users = User::whereIn('id', $userIds)->get();

        return $users->map(function (User $user) use ($deposits, $params, $group): array {
            $deposit = $deposits->get($user->id);
            $state = $this->depositOutcome($deposit);

            return $this->delivery(
                $user,
                "{$group}.{$state}",
                $params,
                $state === 'refund_pending'
                    ? AuctionNotificationCatalog::SCREEN_AUCTION_REFUNDS
                    : AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS,
                ['deposit_status' => $deposit?->status?->value],
            );
        })->all();
    }

    private function depositOutcome(?AuctionDeposit $deposit): string
    {
        if (! $deposit) {
            return 'no_deposit';
        }

        return match ($deposit->status) {
            AuctionDepositStatus::RefundPending, AuctionDepositStatus::Refunded => 'refund_pending',
            AuctionDepositStatus::Held => 'held',
            AuctionDepositStatus::Forfeited => 'forfeited',
            default => 'no_deposit',
        };
    }

    private function winnerPaymentReminder(array $payload, Auction $auction, array $params): array
    {
        $settlement = $auction->settlement;
        $winner = $this->payloads->requiredUser(
            (int) ($payload['winner_id'] ?? $settlement?->winner_id ?? 0),
            'auction.winner_payment_reminder'
        );

        $currency = (string) ($payload['currency_code'] ?? $settlement?->currency_code ?? $auction->currency_code);
        $dueAt = $settlement?->payment_due_at;

        return [
            $this->delivery(
                $winner,
                'winner_payment_reminder.'.$this->reminderBucket((int) ($payload['hours_before'] ?? 0)),
                [
                    ...$params,
                    'amount' => $this->format->money((int) ($payload['remaining_amount_minor'] ?? $settlement?->remaining_amount_minor ?? 0), $currency),
                    'currency' => $currency,
                    'deadline' => $this->format->dateTime($dueAt),
                ],
                AuctionNotificationCatalog::SCREEN_AUCTION_PAYMENT,
                ['deadline' => $dueAt?->toIso8601String(), 'hours_before' => (int) ($payload['hours_before'] ?? 0)],
            ),
        ];
    }

    private function handoverReminder(array $payload, Auction $auction, array $params): array
    {
        $audience = (string) ($payload['audience'] ?? 'seller');
        $settlement = $auction->settlement;
        $dueAt = $settlement?->handover_due_at;

        $user = $audience === 'winner' ? $settlement?->winner : $auction->seller;

        if (! $user instanceof User) {
            throw new \RuntimeException('No notification recipient found for auction.handover_reminder.');
        }

        return [
            $this->delivery(
                $user,
                "handover_reminder.{$audience}.".$this->reminderBucket((int) ($payload['hours_before'] ?? 0)),
                [...$params, 'deadline' => $this->format->dateTime($dueAt)],
                $audience === 'winner'
                    ? AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS
                    : AuctionNotificationCatalog::SCREEN_SELLER_AUCTION,
                ['deadline' => $dueAt?->toIso8601String(), 'hours_before' => (int) ($payload['hours_before'] ?? 0)],
            ),
        ];
    }

    private function reminderBucket(int $hoursBefore): string
    {
        return match (true) {
            $hoursBefore <= 0 => 'overdue',
            $hoursBefore === 1 => 'final',
            default => 'upcoming',
        };
    }

    private function winnerDefaulted(array $payload, Auction $auction, array $params): array
    {
        $defaulted = User::find((int) ($payload['defaulted_user_id'] ?? 0));

        return [
            ...($defaulted ? [$this->delivery($defaulted, 'winner_defaulted_winner', $params, AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS)] : []),
            ...($auction->seller ? [$this->delivery($auction->seller, 'winner_defaulted_seller', $params, AuctionNotificationCatalog::SCREEN_SELLER_AUCTION)] : []),
        ];
    }

    private function alternativeWinner(array $payload, Auction $auction, array $params): array
    {
        $winner = $this->payloads->requiredUser((int) ($payload['new_winner_id'] ?? 0), 'auction.alternative_winner_selected');
        $currency = (string) $auction->currency_code;

        return [
            $this->delivery(
                $winner,
                'alternative_winner_selected',
                [...$params, 'amount' => $this->format->money((int) ($payload['new_amount_minor'] ?? 0), $currency), 'currency' => $currency],
                AuctionNotificationCatalog::SCREEN_AUCTION_PAYMENT,
            ),
        ];
    }

    private function depositEvent(
        array $payload,
        string $key,
        array $params,
        ?string $amountField,
        string $eventType,
        string $screen = AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS,
    ): array {
        $deposit = $this->payloads->deposit($payload);
        if (! $deposit) {
            throw new \RuntimeException("No notification recipient found for {$eventType}.");
        }

        $user = $this->payloads->requiredUser((int) $deposit->user_id, $eventType);
        $currency = (string) $deposit->currency_code;

        if ($amountField !== null) {
            $params = [
                ...$params,
                'amount' => $this->format->money((int) ($payload[$amountField] ?? 0), $currency),
                'currency' => $currency,
            ];
        }

        return [$this->delivery($user, $key, $params, $screen)];
    }

    private function refund(OutboxMessage $message, array $payload, array $params): array
    {
        $refund = $this->payloads->refund($payload);
        if (! $refund) {
            throw new \RuntimeException("No notification recipient found for {$message->event_type}.");
        }

        $user = $this->payloads->requiredUser((int) $refund->user_id, $message->event_type);
        $currency = (string) $refund->currency_code;
        $key = str_replace('auction.', '', $message->event_type);

        return [
            $this->delivery(
                $user,
                $key,
                [...$params, 'amount' => $this->format->money((int) $refund->amount_minor, $currency), 'currency' => $currency],
                AuctionNotificationCatalog::SCREEN_AUCTION_REFUNDS,
            ),
        ];
    }

    private function sellerPayout(OutboxMessage $message, array $payload, array $params): array
    {
        $payout = $this->payloads->sellerPayout($payload);
        if (! $payout) {
            throw new \RuntimeException("No notification recipient found for {$message->event_type}.");
        }

        $seller = $this->payloads->requiredUser((int) $payout->seller_id, $message->event_type);
        $currency = (string) $payout->currency_code;

        $key = match ($message->event_type) {
            'auction.seller_payout_created' => match (true) {
                (bool) ($payload['on_hold'] ?? false) => 'seller_payout.on_hold',
                ! ($payload['has_destination'] ?? true) => 'seller_payout.awaiting_destination',
                default => 'seller_payout.created',
            },
            default => 'seller_payout.'.str_replace('auction.seller_payout_', '', $message->event_type),
        };

        return [
            $this->delivery(
                $seller,
                $key,
                [...$params, 'amount' => $this->format->money((int) $payout->amount_minor, $currency), 'currency' => $currency],
                AuctionNotificationCatalog::SCREEN_SELLER_PAYOUT,
                ['payout_id' => $payout->public_id],
            ),
        ];
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

    private function users(array $users): Collection
    {
        return collect($users)
            ->filter(fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }
}
