<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use App\Http\Resources\Auction\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserAuctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $this->currency_code;
        $settlement = $this->user_settlement;
        $deposit = $this->user_deposit;

        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'status' => $this->status?->value,
            'category' => $this->category?->name,
            'currency' => $currency,
            'starting_amount' => MoneyResource::make((int) $this->starting_amount_minor, $currency),
            'current_amount' => $this->currentLeadingBid
                ? MoneyResource::make((int) $this->currentLeadingBid->amount_minor, $currency)
                : null,
            'metrics' => [
                'bids_count' => (int) ($this->metric?->bids_count ?? 0),
                'participants_count' => (int) ($this->metric?->participants_count ?? 0),
            ],
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'participant_status' => $this->participant_status,
            'participated_at' => $this->participated_at,
            'user_bids_count' => $this->user_bids_count,
            'user_highest_bid' => $this->user_highest_bid_minor !== null
                ? MoneyResource::make((int) $this->user_highest_bid_minor, $currency)
                : null,
            'is_winner' => $settlement !== null,
            'settlement' => $settlement === null ? null : [
                'id' => $settlement->public_id,
                'status' => $settlement->status,
                'winning_amount' => MoneyResource::make((int) $settlement->winning_amount_minor, $settlement->currency_code),
                'amount_due' => MoneyResource::make((int) $settlement->amount_due_minor, $settlement->currency_code),
                'amount_paid' => MoneyResource::make((int) $settlement->amount_paid_minor, $settlement->currency_code),
                'payment_due_at' => $settlement->payment_due_at,
            ],
            'deposit' => $deposit === null ? null : [
                'status' => $deposit->status,
                'held_amount' => MoneyResource::make((int) $deposit->held_amount_minor, $deposit->currency_code),
            ],
            'seller_settlement' => $this->relationLoaded('settlement') && $this->settlement ? [
                'id' => $this->settlement->public_id,
                'status' => $this->settlement->status instanceof \BackedEnum ? $this->settlement->status->value : $this->settlement->status,
                'winning_amount' => MoneyResource::make((int) $this->settlement->winning_amount_minor, $this->settlement->currency_code),
                'seller_net_amount' => MoneyResource::make((int) $this->settlement->seller_net_amount_minor, $this->settlement->currency_code),
            ] : null,
        ];
    }
}
