<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportTicketRead extends Model
{
    protected $fillable = ['ticket_id', 'user_id', 'last_read_message_id', 'read_at'];

    protected $casts = ['read_at' => 'immutable_datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
