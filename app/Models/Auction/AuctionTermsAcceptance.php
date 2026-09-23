<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use App\Models\Concerns\BelongsToMarket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionTermsAcceptance extends Model
{
    use BelongsToMarket, HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'participant_id',
        'user_id',
        'terms_version_id',
        'ip_hash',
        'user_agent',
        'accepted_at',
    ];

    protected $casts = [
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
