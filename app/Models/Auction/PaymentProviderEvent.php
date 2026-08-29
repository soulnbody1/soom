<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentProviderEvent extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'provider',
        'event_id',
        'event_type',
        'payment_transaction_id',
        'provider_transaction_id',
        'signature_verified',
        'payload_redacted',
        'received_at',
        'processed_at',
        'process_error',
    ];

    protected $casts = [
        'signature_verified' => 'boolean',
        'payload_redacted' => 'array',
        'received_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
