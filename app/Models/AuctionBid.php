<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionBid extends Model
{
    protected $fillable = [
        'auction_id',
        'user_id',
        'amount',
        'is_winning',
        'winning_at',
        'deposit_paid',
        'deposit_paid_at',
        'deposit_transaction_id',
        'deposit_status',
        'deposit_processed_at',
        'terms_accepted',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_winning' => 'boolean',
        'winning_at' => 'datetime',
        'deposit_paid' => 'boolean',
        'deposit_paid_at' => 'datetime',
        'deposit_processed_at' => 'datetime',
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

    // Helpers
    public function isHeld(): bool
    {
        return $this->deposit_status === 'held';
    }

    public function isRefunded(): bool
    {
        return $this->deposit_status === 'refunded';
    }

    public function markAsWinning(): void
    {
        $this->update([
            'is_winning' => true,
            'winning_at' => now(),
        ]);
    }

    public function markAsOutbid(): void
    {
        $this->update([
            'is_winning' => false,
            'winning_at' => null,
        ]);
    }

    public function refundDeposit(): void
    {
        if ($this->isHeld()) {
            $this->update([
                'deposit_status' => 'refunded',
                'deposit_processed_at' => now(),
            ]);
        }
    }

    public function applyDepositToPayment(): void
    {
        if ($this->isHeld()) {
            $this->update([
                'deposit_status' => 'applied_to_payment',
                'deposit_processed_at' => now(),
            ]);
        }
    }

    public function forfeitDeposit(): void
    {
        if ($this->isHeld()) {
            $this->update([
                'deposit_status' => 'forfeited',
                'deposit_processed_at' => now(),
            ]);
        }
    }

    // Scopes
    public function scopeWinning($query)
    {
        return $query->where('is_winning', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeHeldDeposits($query)
    {
        return $query->where('deposit_status', 'held');
    }

    public function scopeActive($query)
    {
        return $query->whereHas('auction', function ($q) {
            $q->active();
        });
    }
}
