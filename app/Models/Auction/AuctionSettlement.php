<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionSettlement extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'winning_bid_id',
        'winner_id',
        'status',
        'winning_amount_minor',
        'deposit_applied_minor',
        'platform_fee_minor',
        'seller_net_amount_minor',
        'amount_due_minor',
        'amount_paid_minor',
        'currency_code',
        'payment_due_at',
        'handover_due_at',
        'paid_at',
        'handover_completed_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => SettlementStatus::class,
        'payment_due_at' => 'immutable_datetime',
        'handover_due_at' => 'immutable_datetime',
        'paid_at' => 'immutable_datetime',
        'handover_completed_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function winningBid(): BelongsTo
    {
        return $this->belongsTo(AuctionBid::class, 'winning_bid_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }
}
