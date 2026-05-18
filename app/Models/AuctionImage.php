<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionImage extends Model
{
    protected $fillable = [
        'auction_id',
        'image_path',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
