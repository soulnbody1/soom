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
        'sequence_number',
        'is_current',
        'current_marker',
        'previous_settlement_id',
        'winner_reassignment_id',
        'superseded_at',
        'status',
        'winning_amount_minor',
        'deposit_applied_minor',
        'platform_fee_minor',
        'seller_net_amount_minor',
        'amount_due_minor',
        'amount_paid_minor',
        'remaining_amount_minor',
        'currency_code',
        'payment_due_at',
        'handover_due_at',
        'paid_at',
        'seller_handover_confirmed_at',
        'buyer_receipt_confirmed_at',
        'handover_completed_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => SettlementStatus::class,
        'is_current' => 'boolean',
        'superseded_at' => 'immutable_datetime',
        'payment_due_at' => 'immutable_datetime',
        'handover_due_at' => 'immutable_datetime',
        'paid_at' => 'immutable_datetime',
        'seller_handover_confirmed_at' => 'immutable_datetime',
        'buyer_receipt_confirmed_at' => 'immutable_datetime',
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

    public function previousSettlement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_settlement_id');
    }
}
