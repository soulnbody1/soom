<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Concerns\BelongsToMarket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionMetric extends Model
{
    use BelongsToMarket;

    protected $fillable = [
        'auction_id',
        'views_count',
        'unique_views_count',
        'participants_count',
        'bids_count',
        'unique_bidders_count',
        'extensions_count',
        'last_bid_at',
    ];

    protected $casts = [
        'last_bid_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
