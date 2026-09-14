<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserAdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'price' => $this->price,
            'category' => $this->category?->name,
            'city' => $this->city?->name,
            'thumbnail' => $this->relationLoaded('images') ? $this->images->first()?->image_path : null,
            'is_blocked' => $this->deleted_at !== null,
            'is_featured' => (bool) $this->is_featured,
            'views_count' => (int) ($this->views_count ?? 0),
            'favorites_count' => (int) ($this->favorites_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'interacted_at' => $this->interacted_at,
        ];
    }
}
