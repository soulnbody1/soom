<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PaymentTransaction extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'payment_submission_id',
        'auction_id',
        'user_id',
        'payment_method_id',
        'purpose',
        'status',
        'failure_code',
        'amount_minor',
        'currency_code',
        'captured_amount_minor',
        'captured_currency_code',
        'provider',
        'provider_transaction_id',
        'provider_event_id',
        'provider_payload',
        'checkout_instruction',
        'checkout_claimed_at',
        'provider_fee_minor',
        'settlement_reference',
        'settled_at',
        'idempotency_key',
        'successful_obligation_key',
        'processed_at',
        'expires_at',
    ];

    protected $casts = [
        'purpose' => PaymentPurpose::class,
        'status' => PaymentTransactionStatus::class,
        'provider_payload' => 'array',
        'checkout_instruction' => 'array',
        'checkout_claimed_at' => 'immutable_datetime',
        'provider_fee_minor' => 'integer',
        'captured_amount_minor' => 'integer',
        'processed_at' => 'immutable_datetime',
        'settled_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(PaymentSubmission::class, 'payment_submission_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(RefundTransaction::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentProviderEvent::class);
    }

    public function isOnline(): bool
    {
        return $this->provider !== 'manual';
    }
}
