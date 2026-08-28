<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use App\Http\Resources\Auction\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserPaymentSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'amount' => MoneyResource::make((int) $this->amount_minor, $this->currency_code),
            'payment_method' => $this->paymentMethod?->name,
            'provider_reference' => $this->provider_reference,
            'has_receipt' => $this->receipt_path !== null,
            'review_note' => $this->review_note,
            'auction' => AdminUserAuctionLinkResource::from($this->auction),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
