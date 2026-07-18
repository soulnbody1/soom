<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Models\Auction\AuctionSellerPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SellerPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'auction' => $this->whenLoaded('auction', fn () => [
                'id' => $this->auction->public_id,
                'title' => $this->auction->title,
            ]),
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller->id,
                'name' => $this->seller->name,
                'phone' => $this->seller->phone,
            ]),
            'settlement' => $this->whenLoaded('settlement', fn () => [
                'id' => $this->settlement->public_id,
                'status' => $this->settlement->status->value,
                'deposit_applied' => MoneyResource::make((int) $this->settlement->deposit_applied_minor, $this->currency_code),
                'amount_paid' => MoneyResource::make((int) $this->settlement->amount_paid_minor, $this->currency_code),
                'completed_at' => $this->settlement->completed_at?->toIso8601String(),
            ]),
            'winning_amount' => MoneyResource::make((int) $this->winning_amount_minor, $this->currency_code),
            'platform_fee' => MoneyResource::make((int) $this->platform_fee_minor, $this->currency_code),
            'amount' => MoneyResource::make((int) $this->amount_minor, $this->currency_code),
            'currency' => $this->currency_code,
            'destination' => $this->hasDestinationSnapshot() ? [
                'recipient_name' => $this->recipient_name,
                'identifier_type' => $this->identifier_type,
                'identifier_value' => $this->identifier_value,
            ] : null,
            'payout_method' => $this->payout_method,
            'transfer_reference' => $this->transfer_reference,
            'note' => $this->note,
            'hold_reason' => $this->hold_reason,
            'failure_reason' => $this->failure_reason,
            'has_proof' => $this->proof_path !== null,
            'proof_mime_type' => $this->proof_mime_type,
            'processed_by' => $this->whenLoaded('processor', fn () => $this->processor ? [
                'id' => $this->processor->id,
                'name' => $this->processor->name,
            ] : null),
            'paid_by' => $this->whenLoaded('payer', fn () => $this->payer ? [
                'id' => $this->payer->id,
                'name' => $this->payer->name,
            ] : null),
            'held_at' => $this->held_at?->toIso8601String(),
            'processing_started_at' => $this->processing_started_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function maskedIdentifier(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $visible = mb_substr($value, -4);

        return str_repeat('*', max(0, mb_strlen($value) - mb_strlen($visible))).$visible;
    }

    public static function sellerPayload(AuctionSellerPayout $payout): array
    {
        return [
            'id' => $payout->public_id,
            'status' => $payout->status->value,
            'auction' => $payout->relationLoaded('auction') && $payout->auction ? [
                'id' => $payout->auction->public_id,
                'title' => $payout->auction->title,
            ] : null,
            'amount' => MoneyResource::make((int) $payout->amount_minor, (string) $payout->currency_code),
            'currency' => $payout->currency_code,
            'has_destination' => $payout->hasDestinationSnapshot(),
            'destination' => $payout->hasDestinationSnapshot() ? [
                'recipient_name' => $payout->recipient_name,
                'identifier_type' => $payout->identifier_type,
                'identifier_value' => self::maskedIdentifier($payout->identifier_value),
            ] : null,
            'payout_method' => $payout->payout_method,
            'transfer_reference' => $payout->transfer_reference,
            'paid_at' => $payout->paid_at?->toIso8601String(),
            'created_at' => $payout->created_at?->toIso8601String(),
        ];
    }
}
