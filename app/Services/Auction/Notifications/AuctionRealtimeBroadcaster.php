<?php

declare(strict_types=1);

namespace App\Services\Auction\Notifications;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Events\Auction\AuctionRealtimeEvent;
use App\Models\Auction\Auction;
use App\Models\Auction\OutboxMessage;

final class AuctionRealtimeBroadcaster
{
    public function __construct(
        private readonly OutboxPayloadResolver $payloads,
        private readonly NotificationValueFormatter $format,
    ) {}

    public function broadcast(OutboxMessage $message, Auction $auction): void
    {
        $payload = $message->payload ?? [];

        $data = match ($message->event_type) {
            'auction.status_changed' => $this->statusChanged($payload, $auction),
            'auction.bid_accepted' => $this->bidAccepted($payload, $auction),
            'auction.finalized' => [
                'auction_id' => $auction->public_id,
                'status' => $auction->status->value,
                'sold' => true,
                'amount' => $this->format->money((int) ($auction->settlement?->winning_amount_minor ?? 0), (string) $auction->currency_code),
                'currency' => (string) $auction->currency_code,
                'winner' => ['anonymous' => true],
            ],
            'auction.alternative_winner_selected' => [
                'auction_id' => $auction->public_id,
                'status' => $auction->status->value,
                'amount' => $this->format->money((int) ($payload['new_amount_minor'] ?? 0), (string) $auction->currency_code),
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

    private function statusChanged(array $payload, Auction $auction): ?array
    {
        $to = (string) ($payload['to'] ?? '');

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
                ? $this->format->money((int) $leading->amount_minor, (string) $leading->currency_code)
                : $this->format->money((int) $auction->starting_amount_minor, (string) $auction->currency_code),
            'currency' => (string) $auction->currency_code,
            'bids_count' => (int) ($auction->metric?->bids_count ?? 0),
        ];
    }

    private function bidAccepted(array $payload, Auction $auction): array
    {
        $bid = $this->payloads->bid($payload);
        $amountMinor = (int) ($payload['amount_minor'] ?? $bid?->amount_minor ?? 0);
        $currency = (string) ($payload['currency_code'] ?? $bid?->currency_code ?? $auction->currency_code);

        return [
            'auction_id' => $auction->public_id,
            'bid_id' => (string) ($payload['bid_public_id'] ?? $bid?->public_id ?? ''),
            'amount' => $this->format->money($amountMinor, $currency),
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
}
