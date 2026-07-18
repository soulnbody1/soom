<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'recipient_name' => $this->recipient_name,
            'identifier_type' => $this->identifier_type,
            'identifier_value' => $this->identifier_value,
            'instructions' => $this->instructions,
            'requires_manual_review' => $this->requires_manual_review,
            'is_active' => $this->is_active,
        ];
    }
}
