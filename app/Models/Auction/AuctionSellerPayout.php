<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionSellerPayout extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'settlement_id',
        'seller_id',
        'status',
        'winning_amount_minor',
        'platform_fee_minor',
        'amount_minor',
        'currency_code',
        'destination_id',
        'recipient_name',
        'identifier_type',
        'identifier_value',
        'payout_method',
        'transfer_reference',
        'note',
        'proof_disk',
        'proof_path',
        'proof_mime_type',
        'proof_size_bytes',
        'hold_reason',
        'failure_reason',
        'held_at',
        'processing_started_at',
        'paid_at',
        'failed_at',
        'processed_by',
        'paid_by',
    ];

    protected $casts = [
        'status' => SellerPayoutStatus::class,
        'held_at' => 'immutable_datetime',
        'processing_started_at' => 'immutable_datetime',
        'paid_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AuctionSettlement::class, 'settlement_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(PayoutDestination::class, 'destination_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function hasDestinationSnapshot(): bool
    {
        return $this->recipient_name !== null
            && $this->identifier_type !== null
            && $this->identifier_value !== null;
    }
}
