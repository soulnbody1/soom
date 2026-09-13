<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportTicketEvent extends Model
{
    protected $fillable = ['ticket_id', 'actor_id', 'type', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
