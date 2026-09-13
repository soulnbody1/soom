<?php

declare(strict_types=1);

namespace App\Http\Resources\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now();
        $firstDue = $this->first_response_due_at;
        $resolutionDue = $this->resolution_due_at;

        return [
            'id' => $this->public_id,
            'reference_number' => $this->reference_number,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'category' => new SupportCategoryResource($this->whenLoaded('category')),
            'requester' => $this->when($request->user()?->role === 'admin', fn () => ['id' => $this->requester?->id, 'name' => $this->requester?->name, 'phone' => $this->requester?->phone]),
            'assignee' => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null,
            'context' => $this->context_type ? ['type' => $this->context_type, 'id' => $this->context_id] : null,
            'version' => $this->version,
            'sla' => [
                'first_response_due_at' => $firstDue?->toIso8601String(),
                'resolution_due_at' => $resolutionDue?->toIso8601String(),
                'first_response_breached' => $this->first_responded_at === null && $firstDue?->lt($now),
                'resolution_breached' => $this->resolved_at === null && $resolutionDue?->lt($now),
            ],
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
