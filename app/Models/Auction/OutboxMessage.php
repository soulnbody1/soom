<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\OutboxStatus;
use App\Models\Auction\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

final class OutboxMessage extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'topic',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'status',
        'attempts',
        'available_at',
        'published_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => OutboxStatus::class,
        'available_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
    ];
}
