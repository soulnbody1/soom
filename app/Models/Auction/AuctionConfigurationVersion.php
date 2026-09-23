<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Exceptions\AuctionConfigurationVersionInUseException;
use App\Models\Auction\Concerns\HasPublicId;
use App\Models\Concerns\BelongsToMarket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionConfigurationVersion extends Model
{
    use BelongsToMarket, HasPublicId;

    protected $table = 'auction_configuration_versions';

    protected $fillable = [
        'public_id',
        'version_number',
        'configuration',
        'is_active',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'configuration' => 'array',
        'published_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (AuctionConfigurationVersion $version): void {
            if (! $version->isUsed()) {
                return;
            }

            throw new AuctionConfigurationVersionInUseException;
        });

        self::deleting(function (AuctionConfigurationVersion $version): void {
            if ($version->isUsed()) {
                throw new AuctionConfigurationVersionInUseException;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsed(): bool
    {
        return AuctionConfigurationSnapshot::where('source_configuration_version_id', $this->id)->exists()
            || Auction::where('configuration_version_id', $this->id)
                ->whereNotIn('status', ['draft', 'pending_review', 'rejected'])
                ->exists();
    }

    public function getSellerDepositMinorAttribute(): int
    {
        return (int) ($this->configuration['seller_deposit_minor'] ?? 0);
    }

    public function getBidderDepositMinorAttribute(): int
    {
        return (int) ($this->configuration['bidder_deposit_minor'] ?? 0);
    }

    public function getPlatformFeeTypeAttribute(): string
    {
        return (string) ($this->configuration['platform_fee_type'] ?? 'percentage');
    }

    public function getPlatformFeeBasisPointsAttribute(): int
    {
        return (int) ($this->configuration['platform_fee_basis_points'] ?? 0);
    }

    public function getPlatformFeeFixedMinorAttribute(): int
    {
        return (int) ($this->configuration['platform_fee_fixed_minor'] ?? 0);
    }

    public function getMinimumBidIncrementMinorAttribute(): int
    {
        return (int) ($this->configuration['minimum_bid_increment_minor'] ?? 0);
    }

    public function getExtensionWindowSecondsAttribute(): int
    {
        return (int) ($this->configuration['extension_window_seconds'] ?? 300);
    }

    public function getExtensionDurationSecondsAttribute(): int
    {
        return (int) ($this->configuration['extension_duration_seconds'] ?? 600);
    }

    public function getMaximumExtensionCountAttribute(): int
    {
        return (int) ($this->configuration['maximum_extension_count'] ?? 6);
    }

    public function getWinnerPaymentDeadlineHoursAttribute(): int
    {
        return (int) ($this->configuration['winner_payment_deadline_hours'] ?? 48);
    }

    public function getHandoverDeadlineHoursAttribute(): int
    {
        return (int) ($this->configuration['handover_deadline_hours'] ?? 72);
    }
}
