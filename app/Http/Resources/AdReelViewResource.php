<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdReelViewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'ad_reel_id' => $this->ad_reel_id,
            'user_id' => $this->user_id,
            'viewed_at' => $this->viewed_at,
        ];
    }
}