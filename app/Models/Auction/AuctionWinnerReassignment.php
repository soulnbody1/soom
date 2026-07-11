<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionWinnerReassignment extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'auction_id',
        'from_bid_id',
        'to_bid_id',
        'from_user_id',
        'to_user_id',
        'created_by',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
