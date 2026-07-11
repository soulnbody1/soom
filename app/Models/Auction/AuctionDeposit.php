<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AuctionDeposit extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'auction_id',
        'participant_id',
        'user_id',
        'type',
        'status',
        'required_amount_minor',
        'held_amount_minor',
        'applied_amount_minor',
        'refunded_amount_minor',
        'forfeited_amount_minor',
        'currency_code',
        'idempotency_key',
        'submitted_at',
        'held_at',
        'released_at',
    ];

    protected $casts = [
        'status' => AuctionDepositStatus::class,
        'submitted_at' => 'immutable_datetime',
        'held_at' => 'immutable_datetime',
        'released_at' => 'immutable_datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(AuctionParticipant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentSubmissions(): HasMany
    {
        return $this->hasMany(PaymentSubmission::class, 'deposit_id');
    }
}
