<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentTransaction extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'payment_submission_id',
        'auction_id',
        'user_id',
        'purpose',
        'status',
        'amount_minor',
        'currency_code',
        'provider',
        'provider_transaction_id',
        'provider_event_id',
        'provider_payload',
        'idempotency_key',
        'processed_at',
    ];

    protected $casts = [
        'purpose' => PaymentPurpose::class,
        'status' => PaymentTransactionStatus::class,
        'provider_payload' => 'array',
        'processed_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(PaymentSubmission::class, 'payment_submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
