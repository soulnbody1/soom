<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use App\Http\Resources\Auction\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserRefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'amount' => MoneyResource::make((int) $this->amount_minor, $this->currency_code),
            'reason' => $this->reason,
            'obligation_type' => $this->obligation_type,
            'destination' => [
                'recipient_name' => $this->recipient_name,
                'identifier_type' => $this->identifier_type,
                'identifier_value' => $this->identifier_value,
            ],
            'has_proof' => $this->proof_path !== null,
            'auction' => AdminUserAuctionLinkResource::from($this->auction),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'succeeded_at' => $this->succeeded_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
