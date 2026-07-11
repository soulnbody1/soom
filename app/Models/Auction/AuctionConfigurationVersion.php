<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Models\Auction\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionConfigurationVersion extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'version_number',
        'seller_deposit_minor',
        'bidder_deposit_minor',
        'platform_fee_type',
        'platform_fee_basis_points',
        'platform_fee_fixed_minor',
        'minimum_bid_increment_minor',
        'extension_window_seconds',
        'extension_duration_seconds',
        'maximum_extension_count',
        'winner_payment_deadline_hours',
        'handover_deadline_hours',
        'is_active',
        'effective_from',
        'effective_until',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'effective_from' => 'immutable_datetime',
        'effective_until' => 'immutable_datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
