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
        'event_id',
        'topic',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'status',
        'attempts',
        'available_at',
        'next_retry_at',
        'locked_at',
        'locked_by',
        'processed_at',
        'failed_at',
        'published_at',
        'dead_lettered_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => OutboxStatus::class,
        'available_at' => 'immutable_datetime',
        'next_retry_at' => 'immutable_datetime',
        'locked_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'dead_lettered_at' => 'immutable_datetime',
    ];
}
