<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdReelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ad_id' => $this->ad?->public_id,
            'video_path' => $this->video_path,
            'thumbnail_path' => $this->thumbnail_path,
            'duration' => $this->duration,
            'is_favorit' => (bool) ($this->is_favorit ?? false),
            'viewed_by_user' => (int) ($this->viewed_by_user ?? 0),
            'views_count' => (int) ($this->views_count ?? 0),
            'created_at' => $this->created_at?->toDateTimeString(),
            'ad' => $this->ad ? [
                'id' => $this->ad->public_id,
                'user_id' => $this->ad->user_id,
                'category_id' => $this->ad->category_id,
                'state_id' => $this->ad->state_id,
                'title' => $this->ad->title,
                'description' => $this->ad->description,
                'price' => $this->ad->price,
                'user' => $this->ad->user ? [
                    'id' => $this->ad->user->id,
                    'name' => $this->ad->user->name,
                    'logo' => $this->ad->user->logo,
                    'phone' => $this->ad->user->phone,
                ] : null,
            ] : null,
        ];
    }
}
