<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuctionsConfigurationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'deposit_type' => $this->deposit_type,
            'amount' => (float) $this->amount,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null,
            'duration_days' => $this->duration_days,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}