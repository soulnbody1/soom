<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class HomeAdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'category' => $this->category?->name,
            'location' => implode(', ', array_filter([
                $this->country?->name,
                $this->state?->name,
                $this->city?->name,
            ])),
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
            ],
            'image' => $this->image,
            'views_count' => (int) ($this->views_count ?? 0),
            'is_favorite' => (bool) ($this->is_favorite ?? false),
            'is_featured' => (bool) $this->is_featured,
        ];
    }
}
