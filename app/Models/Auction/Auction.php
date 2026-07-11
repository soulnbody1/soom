<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Auction extends Model
{
    use HasPublicId;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'seller_id',
        'category_id',
        'country_id',
        'state_id',
        'city_id',
        'terms_version_id',
        'currency_code',
        'title',
        'description',
        'latitude',
        'longitude',
        'status',
        'starting_amount_minor',
        'reserve_amount_minor',
        'minimum_bid_increment_minor',
        'seller_deposit_amount_minor',
        'bidder_deposit_amount_minor',
        'platform_fee_type',
        'platform_fee_basis_points',
        'platform_fee_fixed_minor',
        'winner_payment_deadline_hours',
        'handover_deadline_hours',
        'starts_at',
        'original_ends_at',
        'ends_at',
        'extension_window_seconds',
        'extension_duration_seconds',
        'maximum_extension_count',
        'extension_count',
        'last_extended_at',
        'current_leading_bid_id',
        'winning_bid_id',
        'published_at',
        'started_at',
        'ended_at',
        'finalized_at',
        'cancelled_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => AuctionStatus::class,
        'starts_at' => 'immutable_datetime',
        'original_ends_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'last_extended_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
        'finalized_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(AuctionTermsVersion::class, 'terms_version_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(AuctionMedia::class)->orderBy('sort_order');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(AuctionParticipant::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class);
    }

    public function currentLeadingBid(): BelongsTo
    {
        return $this->belongsTo(AuctionBid::class, 'current_leading_bid_id');
    }

    public function winningBid(): BelongsTo
    {
        return $this->belongsTo(AuctionBid::class, 'winning_bid_id');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(AuctionDeposit::class);
    }

    public function sellerDeposit(): HasOne
    {
        return $this->hasOne(AuctionDeposit::class)->where('type', 'seller');
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(AuctionSettlement::class);
    }

    public function metric(): HasOne
    {
        return $this->hasOne(AuctionMetric::class);
    }

    public function scopePublic($query)
    {
        return $query->whereIn('status', array_map(
            static fn (AuctionStatus $status): string => $status->value,
            array_filter(AuctionStatus::cases(), static fn (AuctionStatus $status): bool => $status->isPubliclyVisible())
        ));
    }
}
