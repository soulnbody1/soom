<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'receiver_id' => (int) $this->receiver_id,
            'content' => $this->content,
            'is_read' => $this->is_read,
            'attachment_url' => $this->attachmentUrl(),
            'attachment_type' => $this->attachment_type,
            // Exposed separately so a client can tell "no ad was referenced" apart
            // from "the referenced ad is gone": a soft-deleted ad resolves the
            // relation to null while the foreign key remains on the row.
            'ad_id' => $this->ad_id !== null ? (int) $this->ad_id : null,
            'ad' => $this->ad ? [
                'id' => $this->ad->id,
                'title' => $this->ad->title,
                'description' => $this->ad->description,
                'price' => $this->ad->price,
                'image' => $this->ad->images->first()?->image_path,
            ] : null,
            'created_at' => $this->created_at?->format('Y-m-d H:i'),

        ];
    }
}
