<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionView extends Model
{
    protected $fillable = [
        'auction_id',
        'user_id',
        'viewer_hash',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
