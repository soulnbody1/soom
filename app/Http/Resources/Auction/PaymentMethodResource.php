<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentMethodResource extends JsonResource
{
    private bool $withTransferDetails = false;

    public function withTransferDetails(): self
    {
        $this->withTransferDetails = true;

        return $this;
    }

    public static function detailedCollection($resource): array
    {
        return collect($resource)
            ->map(fn ($method) => (new self($method))->withTransferDetails())
            ->all();
    }

    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'identifier_type' => $this->identifier_type,
            'requires_manual_review' => $this->requires_manual_review,
            'is_active' => $this->is_active,
        ];

        if (! $this->withTransferDetails) {
            return $payload;
        }

        return $payload + [
            'recipient_name' => $this->recipient_name,
            'identifier_value' => $this->identifier_value,
            'instructions' => $this->instructions,
        ];
    }
}
