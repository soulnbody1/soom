<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use App\Http\Resources\Auction\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserDepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'status' => $this->status->value,
            'required_amount' => MoneyResource::make((int) $this->required_amount_minor, $this->currency_code),
            'held_amount' => MoneyResource::make((int) $this->held_amount_minor, $this->currency_code),
            'applied_amount' => MoneyResource::make((int) $this->applied_amount_minor, $this->currency_code),
            'refunded_amount' => MoneyResource::make((int) $this->refunded_amount_minor, $this->currency_code),
            'forfeited_amount' => MoneyResource::make((int) $this->forfeited_amount_minor, $this->currency_code),
            'hold_reason' => $this->hold_reason,
            'auction' => AdminUserAuctionLinkResource::from($this->auction),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'held_at' => $this->held_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
