<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use App\Models\Concerns\BelongsToMarket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionBid extends Model
{
    use BelongsToMarket, HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'participant_id',
        'bidder_id',
        'amount_minor',
        'currency_code',
        'sequence_number',
        'previous_bid_id',
        'idempotency_key',
        'client_request_id',
        'server_received_at',
        'accepted_at',
    ];

    protected $casts = [
        'server_received_at' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(AuctionParticipant::class);
    }

    public function bidder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bidder_id');
    }

    public function previousBid(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_bid_id');
    }
}
