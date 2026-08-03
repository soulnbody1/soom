<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MyRefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'auction' => $this->resource->relationLoaded('auction') && $this->resource->auction
                ? [
                    'id' => $this->resource->auction->public_id,
                    'title' => $this->resource->auction->title,
                    'status' => $this->resource->auction->status->value,
                ]
                : null,
            'amount' => MoneyResource::make((int) $this->amount_minor, (string) $this->currency_code),
            'currency_code' => $this->currency_code,
            'status' => $this->status->value,
            'status_label' => __('auction.refund_statuses.'.$this->status->value),
            'reason' => $this->reason,
            'attempt_count' => (int) $this->attempt_count,
            'requires_user_action' => $this->status === RefundTransactionStatus::Failed,
            'created_at' => $this->created_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'succeeded_at' => $this->succeeded_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
