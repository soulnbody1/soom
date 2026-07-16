<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuctionSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'winning_amount' => MoneyResource::make($this->winning_amount_minor, $this->currency_code),
            'deposit_applied' => MoneyResource::make($this->deposit_applied_minor, $this->currency_code),
            'platform_fee' => MoneyResource::make($this->platform_fee_minor, $this->currency_code),
            'seller_net_amount' => MoneyResource::make($this->seller_net_amount_minor, $this->currency_code),
            'amount_due' => MoneyResource::make($this->amount_due_minor, $this->currency_code),
            'amount_paid' => MoneyResource::make($this->amount_paid_minor, $this->currency_code),
            'payment_due_at' => $this->payment_due_at?->toIso8601String(),
            'handover_due_at' => $this->handover_due_at?->toIso8601String(),
            'winner' => $this->whenLoaded('winner', fn () => $this->winner ? [
                'id' => $this->winner->id,
                'name' => $this->winner->name,
            ] : null),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'seller_handover_confirmed_at' => $this->seller_handover_confirmed_at?->toIso8601String(),
            'buyer_receipt_confirmed_at' => $this->buyer_receipt_confirmed_at?->toIso8601String(),
            'handover_completed_at' => $this->handover_completed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'defaulted_at' => $this->defaulted_at?->toIso8601String(),
        ];
    }
}
