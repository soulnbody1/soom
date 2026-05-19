<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Auction extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'description',
        'country_id',
        'state_id',
        'city_id',
        'latitude',
        'longitude',
        'starting_price',
        'current_bid',
        'min_accept_price',
        'deposit_amount',
        'status',
        'starts_at',
        'ends_at',
        'original_ends_at',
        'winner_id',
        'duration_days',
        'terms_accepted',
        'advertiser_deposit_paid',
        'advertiser_deposit_paid_at',
        'advertiser_deposit_transaction_id',
    ];

    protected $casts = [
        'starting_price' => 'decimal:2',
        'current_bid' => 'decimal:2',
        'min_accept_price' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'advertiser_deposit_paid' => 'boolean',
        'advertiser_deposit_paid_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'original_ends_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    // العلاقات
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class)->orderBy('amount', 'desc');
    }

    public function winningBid(): HasOne
    {
        return $this->hasOne(AuctionBid::class)->where('is_winning', true);
    }

    public function images(): HasMany
    {
        return $this->hasMany(AuctionImage::class)->orderBy('order');
    }

    public function views(): HasMany
    {
        return $this->hasMany(AuctionView::class);
    }

    // Helpers
    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'extended']);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['closed', 'completed', 'cancelled']);
    }

    public function getDepositAmount(): float
    {
        return (float) ($this->deposit_amount ?? 0);
    }

    public function getTimeRemaining(): int
    {
        if (!$this->ends_at || $this->isClosed()) {
            return 0;
        }
        
        return max(0, $this->ends_at->diffInSeconds(now()));
    }

    public function shouldExtend(): bool
    {
        if (!$this->isActive()) {
            return false;
        }
        
        $secondsRemaining = $this->getTimeRemaining();
        return $secondsRemaining > 0 && $secondsRemaining <= 300;
    }

    public function extend(int $minutes = 10): void
    {
        if ($this->original_ends_at === null) {
            $this->original_ends_at = $this->ends_at;
        }
        
        $this->ends_at = $this->ends_at->addMinutes($minutes);
        $this->status = 'extended';
        $this->save();
    }

    public function getTimeRemainingFormatted(): string
    {
        if (!$this->ends_at) {
            return 'غير محدد';
        }
        
        $now = now();
        $endsAt = $this->ends_at;
        
        if ($now->greaterThan($endsAt)) {
            return 'انتهى المزاد';
        }
        
        $diff = $now->diff($endsAt);
        
        $parts = [];
        
        if ($diff->d > 0) {
            $parts[] = $diff->d . ' يوم';
        }
        
        if ($diff->h > 0) {
            $parts[] = $diff->h . ' س';
        }
        
        if ($diff->i > 0) {
            $parts[] = $diff->i . ' د';
        }
        
        return empty($parts) ? 'أقل من دقيقة' : implode(' و ', $parts);
    }

    public function getLocation(): string
    {
        $parts = array_filter([
            $this->country?->name,
            $this->state?->name,
            $this->city?->name,
        ]);
        
        return implode(', ', $parts);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'extended']);
    }

    public function scopePendingPayment($query)
    {
        return $query->where('status', 'pending_payment');
    }

    public function scopeExpired($query)
    {
        return $query->where('ends_at', '<', now())
                     ->whereIn('status', ['active', 'extended']);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
