<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\ValueObjects\Currency;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;

final class AuctionConfigurationSnapshotFactory
{
    public function __construct(
        private readonly AuctionConfigurationSnapshotHasher $hasher,
        private readonly AuctionConfigurationSnapshotValidator $validator,
    ) {}

    public function fromAuction(Auction $auction, AuctionConfigurationVersion $sourceVersion, ?int $createdBy): array
    {
        $configuration = (array) $sourceVersion->configuration;
        $currency = Currency::fromCode((string) $auction->currency_code);
        $winnerPolicy = (array) ($configuration['winner_default_deposit_policy'] ?? []);
        $sellerPolicy = (array) ($configuration['seller_deposit_policy'] ?? []);
        $nonWinnerPolicy = (string) ($configuration['non_winner_deposit_policy'] ?? '');
        $candidateLimit = (int) (
            $configuration['non_winner_deposit_hold_count']
            ?? $configuration['hold_top_n_bidders']
            ?? $configuration['alternative_winner_candidate_count']
            ?? 0
        );

        $platformFeeType = (string) $auction->platform_fee_type;
        $platformFeeValue = $platformFeeType === 'fixed'
            ? (int) $auction->platform_fee_fixed_minor
            : (int) $auction->platform_fee_basis_points;

        $data = [
            'auction_id' => (int) $auction->id,
            'source_configuration_version_id' => (int) $sourceVersion->id,
            'terms_version_id' => $auction->terms_version_id ? (int) $auction->terms_version_id : null,
            'currency_code' => $currency->code,
            'minimum_bid_increment_minor' => (int) $auction->minimum_bid_increment_minor,
            'auto_extend_enabled' => (int) $auction->extension_window_seconds > 0 && (int) $auction->extension_duration_seconds > 0,
            'auto_extend_window_seconds' => (int) $auction->extension_window_seconds,
            'auto_extend_duration_seconds' => (int) $auction->extension_duration_seconds,
            'maximum_extensions' => (int) $auction->maximum_extension_count,
            'seller_deposit_required_minor' => (int) $auction->seller_deposit_amount_minor,
            'seller_deposit_policy' => $sellerPolicy,
            'bidder_deposit_required_minor' => (int) $auction->bidder_deposit_amount_minor,
            'non_winner_deposit_hold_policy' => $nonWinnerPolicy,
            'alternative_candidate_limit' => $candidateLimit,
            'winner_payment_deadline_minutes' => (int) $auction->winner_payment_deadline_hours * 60,
            'handover_deadline_minutes' => (int) $auction->handover_deadline_hours * 60,
            'platform_fee_type' => $platformFeeType,
            'platform_fee_value' => $platformFeeValue,
            'platform_fee_min_minor' => (int) ($configuration['platform_fee_min_minor'] ?? 0),
            'platform_fee_max_minor' => isset($configuration['platform_fee_max_minor']) ? (int) $configuration['platform_fee_max_minor'] : null,
            'winner_default_deposit_policy' => $winnerPolicy,
            'alternative_winner_enabled' => (bool) ($configuration['alternative_winner_enabled'] ?? true),
            'created_by' => $createdBy,
            'finalized_at' => now(),
        ];

        $this->validator->assertValid($data);
        $data['snapshot_hash'] = $this->hasher->hash($data);

        return $data;
    }
}
