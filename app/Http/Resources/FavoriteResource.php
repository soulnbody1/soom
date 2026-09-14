<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'ad_id' => $this->ad?->public_id,
            'ad' => AdResource::make($this->whenLoaded('ad')),
            'added_at' => $this->created_at?->diffForHumans(),
        ];
    }
}
