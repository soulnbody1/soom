<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AuctionBid extends Model
{
    protected $fillable = [
        'auction_id',
        'user_id',
        'amount',
        'is_winning',
        'winning_at',
        'terms_accepted',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_winning' => 'integer',
        'winning_at' => 'datetime',
        'terms_accepted' => 'boolean',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * العلاقة polymorphic مع جدول التأمينات الموحد
     */
    public function deposits(): MorphMany
    {
        return $this->morphMany(AuctionDeposit::class, 'depositable');
    }

    /**
     * التأمين الخاص بهذه المزايدة
     */
    public function deposit(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(AuctionDeposit::class, 'depositable')
                    ->where('deposit_type', 'bidder');
    }

    // Helpers
    public function isHeld(): bool
    {
        return $this->deposit()->where('deposit_status', 'held')->exists();
    }

    public function isRefunded(): bool
    {
        return $this->deposit()->where('deposit_status', 'refunded')->exists();
    }

    public function markAsWinning(): void
    {
        $this->update([
            'is_winning' => $this->user_id,
            'winning_at' => now(),
        ]);
    }

    public function markAsOutbid(): void
    {
        $this->update([
            'is_winning' => null,
            'winning_at' => null,
        ]);
    }

    public function refundDeposit(): void
    {
        $deposit = $this->deposit;
        if ($deposit && $deposit->isHeld()) {
            $deposit->refund();
        }
    }

    public function applyDepositToPayment(): void
    {
        $deposit = $this->deposit;
        if ($deposit && $deposit->isHeld()) {
            $deposit->applyToPayment();
        }
    }

    public function forfeitDeposit(): void
    {
        $deposit = $this->deposit;
        if ($deposit && $deposit->isHeld()) {
            $deposit->forfeit();
        }
    }

    public function getDepositStatus(): ?string
    {
        return $this->deposit?->deposit_status;
    }

    public function isDepositPaid(): bool
    {
        return $this->deposit()->where('paid', true)->exists();
    }

    // Scopes
    public function scopeWinning($query)
    {
        return $query->whereNotNull('is_winning');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeHeldDeposits($query)
    {
        return $query->whereHas('deposit', function ($q) {
            $q->where('deposit_status', 'held');
        });
    }

    public function scopeActive($query)
    {
        return $query->whereHas('auction', function ($q) {
            $q->active();
        });
    }
}
