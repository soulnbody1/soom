<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->public_id),
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'amount' => MoneyResource::make($this->amount_minor, $this->currency_code),
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => [
                'id' => $this->paymentMethod->public_id,
                'name' => $this->paymentMethod->name,
                'code' => $this->paymentMethod->code,
            ]),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->when(
                $request->user()?->role === 'admin' || $request->user()?->id === $this->user_id,
                $this->review_note
            ),
        ];
    }
}
