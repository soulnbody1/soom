<?php

declare(strict_types=1);

namespace App\Models\Auction;

use Illuminate\Database\Eloquent\Model;

final class AuctionStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'auction_status_history';

    protected $fillable = [
        'auction_id',
        'from_status',
        'to_status',
        'changed_by',
        'actor_type',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
