<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_id' => (int) $this->sender_id,
            'receiver_id' => (int) $this->receiver_id,
            'content' => $this->content,
            'attachment_url' => $this->attachmentUrl(),
            'attachment_type' => $this->attachment_type,
            'is_read' => (bool) $this->is_read,
            'ad' => $this->ad ? ['id' => $this->ad->id, 'title' => $this->ad->title, 'price' => $this->ad->price] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
