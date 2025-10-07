<?php


namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'logo' => $this->user->logo,
            ],
            'last_message' => [
                'id' => $this->last_message->id,
                'content' => $this->last_message->content,
                'from_me' => $this->last_message->from_me,
                'created_at' => $this->last_message->created_at,
            ],
            'unread_count' => $this->unread_count,
        ];
    }
}
