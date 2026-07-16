<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
