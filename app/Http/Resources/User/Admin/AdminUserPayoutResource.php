<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use App\Http\Resources\Auction\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'amount' => MoneyResource::make((int) $this->amount_minor, $this->currency_code),
            'winning_amount' => MoneyResource::make((int) $this->winning_amount_minor, $this->currency_code),
            'platform_fee' => MoneyResource::make((int) $this->platform_fee_minor, $this->currency_code),
            'destination' => [
                'recipient_name' => $this->recipient_name,
                'identifier_type' => $this->identifier_type,
                'identifier_value' => $this->identifier_value,
            ],
            'transfer_reference' => $this->transfer_reference,
            'has_proof' => $this->proof_path !== null,
            'hold_reason' => $this->hold_reason,
            'failure_reason' => $this->failure_reason,
            'auction' => AdminUserAuctionLinkResource::from($this->auction),
            'held_at' => $this->held_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
