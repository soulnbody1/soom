<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $partner = $this->resource['partner'];
        $message = $this->resource['last_message'];

        return [
            'partner' => [
                'id' => $partner->id,
                'name' => $partner->name,
                'phone' => $partner->phone,
                'logo' => $partner->logo,
                'is_blocked' => $partner->deleted_at !== null,
            ],
            'last_message' => [
                'preview' => $message->content,
                'has_attachment' => $message->attachment_type !== null,
                'from_user' => $message->sender_id === $this->resource['viewer_id'],
                'is_read' => (bool) $message->is_read,
                'ad' => $message->ad ? ['id' => $message->ad->public_id, 'title' => $message->ad->title] : null,
                'created_at' => $message->created_at?->toIso8601String(),
            ],
            'messages_count' => $this->resource['messages_count'],
            'unread_count' => $this->resource['unread_count'],
        ];
    }
}
