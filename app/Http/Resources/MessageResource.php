<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'             => $this->id,
            'sender_id'      => $this->sender_id,
            'receiver_id'    => (int) $this->receiver_id,
            'content'        => $this->content,
            'is_read'        => $this->is_read,
            'attachment_url' => $this->attachmentUrl(),
            'attachment_type' => $this->attachment_type,
            'ad' => $this->ad ? [
                'id'          => $this->ad->id,
                'title'       => $this->ad->title,
                'description' => $this->ad->description,
                'price'       => $this->ad->price,
                'image'       => $this->ad->images->first()?->image_path,
            ] : null,
            'created_at'     => $this->created_at?->format('Y-m-d H:i'),

        ];
    }
}
