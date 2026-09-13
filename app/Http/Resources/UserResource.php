<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'logo' => $this->logo,
            'cover' => $this->cover,
            'birth_date' => $this->birth_date,
            'gender' => $this->gender,
            'address' => implode(',', array_filter([
                $this->country?->name,
                $this->state?->name,
                $this->city?->name,
            ])),
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'allow_ad_notifications' => (bool) $this->allow_ad_notifications,
            'is_blocked' => $this->deleted_at,
            'hasAds' => (bool) ($this->ads_count ?? 0),
            'created_at' => $this->created_at,
        ];
    }
}
