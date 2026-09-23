<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\Concerns\BelongsToMarket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RefundTransaction extends Model
{
    use BelongsToMarket, HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'deposit_id',
        'payment_transaction_id',
        'obligation_type',
        'obligation_id',
        'user_id',
        'destination_id',
        'recipient_name',
        'identifier_type',
        'identifier_value',
        'status',
        'amount_minor',
        'held_refund_amount_minor',
        'applied_refund_amount_minor',
        'provider_fee_minor',
        'currency_code',
        'reason',
        'provider',
        'provider_refund_id',
        'provider_response',
        'idempotency_key',
        'attempt_count',
        'failure_reason',
        'last_error',
        'next_retry_at',
        'processing_started_at',
        'processing_token',
        'lease_expires_at',
        'manual_confirmed_by',
        'manual_confirmed_at',
        'manual_confirmation_reason',
        'proof_disk',
        'proof_path',
        'proof_mime_type',
        'proof_size_bytes',
        'processed_at',
        'succeeded_at',
        'failed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => RefundTransactionStatus::class,
        'provider_response' => 'array',
        'next_retry_at' => 'immutable_datetime',
        'processing_started_at' => 'immutable_datetime',
        'lease_expires_at' => 'immutable_datetime',
        'manual_confirmed_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
        'succeeded_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(AuctionDeposit::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(PayoutDestination::class, 'destination_id');
    }

    public function hasDestinationSnapshot(): bool
    {
        return $this->recipient_name !== null
            && $this->identifier_type !== null
            && $this->identifier_value !== null;
    }

    public function manualConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_confirmed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
