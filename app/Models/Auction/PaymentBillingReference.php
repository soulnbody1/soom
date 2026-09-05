<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\ValueObjects\BillingReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentBillingReference extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'reference',
        'allocated_at',
    ];

    protected $casts = [
        'allocated_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function billingReference(): BillingReference
    {
        return new BillingReference((string) $this->reference);
    }
}
