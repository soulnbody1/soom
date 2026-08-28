<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'event_type' => $this->event_type,
            'actor_type' => $this->actor_type,
            'metadata' => $this->metadata,
            'auction' => AdminUserAuctionLinkResource::from($this->auction),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
