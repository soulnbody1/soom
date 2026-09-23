<?php

declare(strict_types=1);

namespace App\Models\Auction;

use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotImmutableException;
use App\Models\Concerns\BelongsToMarket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionConfigurationSnapshot extends Model
{
    use BelongsToMarket;

    protected $fillable = [
        'auction_id',
        'source_configuration_version_id',
        'terms_version_id',
        'currency_code',
        'minimum_bid_increment_minor',
        'auto_extend_enabled',
        'auto_extend_window_seconds',
        'auto_extend_duration_seconds',
        'maximum_extensions',
        'seller_deposit_required_minor',
        'seller_deposit_policy',
        'bidder_deposit_required_minor',
        'non_winner_deposit_hold_policy',
        'alternative_candidate_limit',
        'winner_payment_deadline_minutes',
        'winner_payment_grace_period_minutes',
        'winner_payment_reminder_hours',
        'seller_deposit_deadline_minutes',
        'review_sla_minutes',
        'handover_deadline_minutes',
        'handover_reminder_hours',
        'platform_fee_type',
        'platform_fee_value',
        'platform_fee_min_minor',
        'platform_fee_max_minor',
        'winner_default_deposit_policy',
        'alternative_winner_enabled',
        'snapshot_hash',
        'created_by',
        'finalized_at',
    ];

    protected $casts = [
        'auction_id' => 'integer',
        'source_configuration_version_id' => 'integer',
        'terms_version_id' => 'integer',
        'minimum_bid_increment_minor' => 'integer',
        'auto_extend_enabled' => 'boolean',
        'auto_extend_window_seconds' => 'integer',
        'auto_extend_duration_seconds' => 'integer',
        'maximum_extensions' => 'integer',
        'seller_deposit_required_minor' => 'integer',
        'seller_deposit_policy' => 'array',
        'bidder_deposit_required_minor' => 'integer',
        'alternative_candidate_limit' => 'integer',
        'winner_payment_deadline_minutes' => 'integer',
        'winner_payment_grace_period_minutes' => 'integer',
        'winner_payment_reminder_hours' => 'array',
        'seller_deposit_deadline_minutes' => 'integer',
        'review_sla_minutes' => 'integer',
        'handover_deadline_minutes' => 'integer',
        'handover_reminder_hours' => 'array',
        'platform_fee_value' => 'integer',
        'platform_fee_min_minor' => 'integer',
        'platform_fee_max_minor' => 'integer',
        'winner_default_deposit_policy' => 'array',
        'alternative_winner_enabled' => 'boolean',
        'finalized_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new AuctionConfigurationSnapshotImmutableException;
        });

        self::deleting(function (): void {
            throw new AuctionConfigurationSnapshotImmutableException;
        });
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function sourceConfigurationVersion(): BelongsTo
    {
        return $this->belongsTo(AuctionConfigurationVersion::class, 'source_configuration_version_id');
    }

    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(AuctionTermsVersion::class, 'terms_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function platformFeeFor(int $amountMinor): int
    {
        $fee = $this->platform_fee_type === 'fixed'
            ? min($amountMinor, (int) $this->platform_fee_value)
            : intdiv($amountMinor * (int) $this->platform_fee_value, 10_000);

        $fee = max((int) $this->platform_fee_min_minor, $fee);

        if ($this->platform_fee_max_minor !== null) {
            $fee = min((int) $this->platform_fee_max_minor, $fee);
        }

        return min($amountMinor, $fee);
    }

    public function sellerDepositDisposition(string $policyKey): ?string
    {
        return $this->seller_deposit_policy[$policyKey] ?? null;
    }

    public function sellerDepositForfeitAmount(string $policyKey): int
    {
        return (int) ($this->seller_deposit_policy["{$policyKey}_forfeit_amount_minor"] ?? 0);
    }

    public function winnerDefaultDepositDisposition(): string
    {
        return (string) ($this->winner_default_deposit_policy['disposition'] ?? 'manual_review');
    }

    public function winnerDefaultDepositForfeitAmount(): int
    {
        return (int) ($this->winner_default_deposit_policy['forfeit_amount_minor'] ?? 0);
    }

    public function winnerPaymentReminderHours(): array
    {
        return $this->normalizedReminderHours(
            $this->winner_payment_reminder_hours,
            'auction.deadlines.winner_payment_reminder_hours_before'
        );
    }

    public function handoverReminderHours(): array
    {
        return $this->normalizedReminderHours(
            $this->handover_reminder_hours,
            'auction.deadlines.handover_reminder_hours_before'
        );
    }

    public function winnerPaymentGracePeriodMinutes(): int
    {
        return (int) ($this->winner_payment_grace_period_minutes
            ?? config('auction.deadlines.winner_payment_grace_period_hours', 24) * 60);
    }

    public function sellerDepositDeadlineMinutes(): int
    {
        return (int) ($this->seller_deposit_deadline_minutes
            ?? config('auction.deadlines.seller_deposit_deadline_hours', 48) * 60);
    }

    private function normalizedReminderHours(mixed $frozen, string $configKey): array
    {
        $hours = is_array($frozen) && $frozen !== [] ? $frozen : (array) config($configKey, []);

        $hours = array_values(array_unique(array_filter(
            array_map('intval', $hours),
            static fn (int $hour): bool => $hour > 0
        )));

        rsort($hours);

        return $hours;
    }
}
