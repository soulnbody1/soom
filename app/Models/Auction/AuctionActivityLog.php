<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

final class AuctionActivityLog extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'auction_id',
        'user_id',
        'event_type',
        'actor_type',
        'ip_hash',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
