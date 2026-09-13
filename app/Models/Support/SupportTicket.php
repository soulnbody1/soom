<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Domain\Support\Enums\SupportTicketPriority;
use App\Domain\Support\Enums\SupportTicketStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class SupportTicket extends Model
{
    use HasPublicId;

    protected $fillable = ['public_id', 'reference_number', 'requester_id', 'category_id', 'assigned_to', 'subject', 'status', 'priority', 'context_type', 'context_id', 'last_message_id', 'last_message_at', 'first_response_due_at', 'resolution_due_at', 'first_responded_at', 'resolved_at', 'closed_at', 'reopened_at', 'version'];

    protected $casts = [
        'status' => SupportTicketStatus::class,
        'priority' => SupportTicketPriority::class,
        'last_message_at' => 'immutable_datetime',
        'first_response_due_at' => 'immutable_datetime',
        'resolution_due_at' => 'immutable_datetime',
        'first_responded_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'reopened_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (SupportTicket $ticket): void {
            if (empty($ticket->public_id)) {
                $ticket->public_id = (string) Str::ulid();
            }
        });
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupportCategory::class, 'category_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(SupportTicketRead::class, 'ticket_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id');
    }
}
