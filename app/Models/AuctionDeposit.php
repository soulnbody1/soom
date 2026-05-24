<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionDeposit extends Model
{
    protected $table = 'auction_deposits';

    protected $fillable = [
        'user_id',
        'depositable_id',
        'depositable_type',
        'paid',
        'paid_at',
        'transaction_id',
        'amount',
        'deposit_status',
        'processed_at',
        'deposit_type',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'paid' => 'boolean',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * العلاقة polymorphic: تشير إما للمزاد (Auction) أو للمزايدة (AuctionBid)
     */
    public function depositable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * اليوزر اللي دفع التأمين
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // ===== Helpers =====

    public function isHeld(): bool
    {
        return $this->deposit_status === 'held';
    }

    public function isRefunded(): bool
    {
        return $this->deposit_status === 'refunded';
    }

    public function isAdvertiserDeposit(): bool
    {
        return $this->deposit_type === 'advertiser';
    }

    public function isBidderDeposit(): bool
    {
        return $this->deposit_type === 'bidder';
    }

    public function markAsPaid(string $transactionId, ?float $amount = null): void
    {
        $this->update([
            'paid' => true,
            'paid_at' => now(),
            'transaction_id' => $transactionId,
            'amount' => $amount ?? $this->amount,
        ]);
    }

    public function refund(): void
    {
        if ($this->isHeld()) {
            $this->update([
                'deposit_status' => 'refunded',
                'processed_at' => now(),
            ]);
        }
    }

    public function applyToPayment(): void
    {
        if ($this->isHeld()) {
            $this->update([
                'deposit_status' => 'applied_to_payment',
                'processed_at' => now(),
            ]);
        }
    }

    public function forfeit(): void
    {
        if ($this->isHeld()) {
            $this->update([
                'deposit_status' => 'forfeited',
                'processed_at' => now(),
            ]);
        }
    }

    // ===== Scopes =====

    public function scopeAdvertiserDeposits($query)
    {
        return $query->where('deposit_type', 'advertiser');
    }

    public function scopeBidderDeposits($query)
    {
        return $query->where('deposit_type', 'bidder');
    }

    public function scopeHeld($query)
    {
        return $query->where('deposit_status', 'held');
    }

    public function scopePaid($query)
    {
        return $query->where('paid', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForAuction($query, int $auctionId)
    {
        return $query->where('depositable_type', Auction::class)
                     ->where('depositable_id', $auctionId);
    }

    public function scopeForBid($query, int $bidId)
    {
        return $query->where('depositable_type', AuctionBid::class)
                     ->where('depositable_id', $bidId);
    }
}