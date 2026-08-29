<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'failure_code' => $this->failure_code,
            'provider' => $this->provider,
            'amount' => MoneyResource::make((int) $this->amount_minor, (string) $this->currency_code),
            'checkout' => $this->checkout_instruction,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
