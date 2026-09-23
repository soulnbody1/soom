<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use App\Models\Concerns\BelongsToMarket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionMedia extends Model
{
    use BelongsToMarket, HasPublicId;

    protected $table = 'auction_media';

    protected $fillable = [
        'public_id',
        'auction_id',
        'disk',
        'path',
        'mime_type',
        'size_bytes',
        'sort_order',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
