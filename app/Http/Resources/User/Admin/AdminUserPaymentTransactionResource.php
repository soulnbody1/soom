<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use App\Http\Resources\Auction\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserPaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'amount' => MoneyResource::make((int) $this->amount_minor, $this->currency_code),
            'provider' => $this->provider,
            'auction' => AdminUserAuctionLinkResource::from($this->auction),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
