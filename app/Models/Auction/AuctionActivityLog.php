<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use App\Models\Concerns\BelongsToMarket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionActivityLog extends Model
{
    use BelongsToMarket, HasPublicId;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
