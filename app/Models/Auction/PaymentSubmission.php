<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class PaymentSubmission extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'deposit_id',
        'settlement_id',
        'user_id',
        'payment_method_id',
        'purpose',
        'status',
        'amount_minor',
        'currency_code',
        'receipt_disk',
        'receipt_path',
        'receipt_mime_type',
        'receipt_size_bytes',
        'provider_reference',
        'idempotency_key',
        'reviewed_by',
        'review_note',
        'submitted_at',
        'reviewed_at',
    ];

    protected $casts = [
        'purpose' => PaymentPurpose::class,
        'status' => PaymentSubmissionStatus::class,
        'submitted_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(AuctionDeposit::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AuctionSettlement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class);
    }
}
