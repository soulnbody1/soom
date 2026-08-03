<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class AuctionParticipant extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'user_id',
        'status',
        'registered_at',
        'qualified_at',
        'blocked_at',
        'block_reason',
    ];

    protected $casts = [
        'status' => AuctionParticipantStatus::class,
        'registered_at' => 'immutable_datetime',
        'qualified_at' => 'immutable_datetime',
        'blocked_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bidderDeposit(): HasOne
    {
        return $this->hasOne(AuctionDeposit::class, 'participant_id')->where('type', 'bidder');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class, 'participant_id');
    }

    public function termsAcceptance(): HasOne
    {
        return $this->hasOne(AuctionTermsAcceptance::class, 'participant_id')->latestOfMany();
    }
}
