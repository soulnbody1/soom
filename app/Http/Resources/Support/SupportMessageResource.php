<?php

declare(strict_types=1);

namespace App\Http\Resources\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupportMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'author_type' => $this->author_type->value,
            'visibility' => $this->visibility->value,
            'body' => $this->body,
            'author' => $this->author ? ['id' => $this->author->id, 'name' => $this->author->name] : null,
            'client_message_id' => $this->client_message_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
