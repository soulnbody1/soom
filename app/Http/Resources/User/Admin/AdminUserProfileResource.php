<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'logo' => $this->logo,
            'role' => $this->role,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date,
            'address' => [
                'country' => $this->country?->name,
                'state' => $this->state?->name,
                'city' => $this->city?->name,
            ],
            'is_blocked' => $this->deleted_at !== null,
            'blocked_at' => $this->deleted_at?->toIso8601String(),
            'email_verified' => $this->email_verified_at !== null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'allow_ad_notifications' => (bool) $this->allow_ad_notifications,
            'has_push_token' => $this->fcm_token !== null,
            'auction_permissions' => $this->auction_permissions ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
