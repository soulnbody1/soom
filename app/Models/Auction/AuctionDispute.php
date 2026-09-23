<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use App\Models\Concerns\BelongsToMarket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionDispute extends Model
{
    use BelongsToMarket, HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'settlement_id',
        'opened_by',
        'resolved_by',
        'status',
        'reason',
        'resolution_note',
        'opened_at',
        'resolved_at',
    ];

    protected $casts = [
        'opened_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AuctionSettlement::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
