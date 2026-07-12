<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RefundTransaction extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'deposit_id',
        'payment_transaction_id',
        'obligation_type',
        'obligation_id',
        'user_id',
        'status',
        'amount_minor',
        'currency_code',
        'reason',
        'provider',
        'provider_refund_id',
        'idempotency_key',
        'failure_reason',
        'processed_at',
    ];

    protected $casts = [
        'status' => RefundTransactionStatus::class,
        'processed_at' => 'immutable_datetime',
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
}
