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
        $terms = $auction->termsVersion;
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
            'terms_hash' => $terms ? hash('sha256', (string) $terms->body) : null,
            'currency_code' => $currency->code,
            'currency_minor_unit' => $currency->exponent(),
            'minimum_bid_increment_minor' => (int) $auction->minimum_bid_increment_minor,
            'reserve_price_policy' => 'auction_row_reserve_amount',
            'auto_extend_enabled' => (int) $auction->extension_window_seconds > 0 && (int) $auction->extension_duration_seconds > 0,
            'auto_extend_window_seconds' => (int) $auction->extension_window_seconds,
            'auto_extend_duration_seconds' => (int) $auction->extension_duration_seconds,
            'maximum_extensions' => (int) $auction->maximum_extension_count,
            'seller_deposit_required_minor' => (int) $auction->seller_deposit_amount_minor,
            'seller_deposit_payment_deadline_minutes' => isset($configuration['seller_deposit_payment_deadline_minutes'])
                ? (int) $configuration['seller_deposit_payment_deadline_minutes']
                : null,
            'seller_deposit_policy' => $sellerPolicy,
            'bidder_deposit_required_minor' => (int) $auction->bidder_deposit_amount_minor,
            'bidder_deposit_payment_deadline_policy' => (string) ($configuration['bidder_deposit_payment_deadline_policy'] ?? 'auction_end'),
            'non_winner_deposit_hold_policy' => $nonWinnerPolicy,
            'alternative_candidate_hold_policy' => $nonWinnerPolicy,
            'alternative_candidate_limit' => $candidateLimit,
            'winner_payment_deadline_minutes' => (int) $auction->winner_payment_deadline_hours * 60,
            'handover_deadline_minutes' => (int) $auction->handover_deadline_hours * 60,
            'platform_fee_type' => $platformFeeType,
            'platform_fee_value' => $platformFeeValue,
            'platform_fee_min_minor' => (int) ($configuration['platform_fee_min_minor'] ?? 0),
            'platform_fee_max_minor' => isset($configuration['platform_fee_max_minor']) ? (int) $configuration['platform_fee_max_minor'] : null,
            'deposit_application_policy' => (string) ($configuration['deposit_application_policy'] ?? 'apply_bidder_deposit_to_winning_amount'),
            'winner_default_deposit_policy' => $winnerPolicy,
            'winner_default_forfeit_type' => (string) ($winnerPolicy['disposition'] ?? 'configured_policy'),
            'winner_default_forfeit_value' => (int) ($winnerPolicy['forfeit_amount_minor'] ?? 0),
            'alternative_winner_enabled' => (bool) ($configuration['alternative_winner_enabled'] ?? true),
            'alternative_winner_selection_policy' => (string) ($configuration['alternative_winner_selection_policy'] ?? 'next_highest_eligible_bid'),
            'maximum_reassignments' => (int) ($configuration['maximum_reassignments'] ?? 1),
            'cancellation_policies' => (array) ($configuration['cancellation_policies'] ?? [
                'seller_deposit_policy' => $sellerPolicy,
            ]),
            'refund_processing_mode' => (string) ($configuration['refund_processing_mode'] ?? 'manual'),
            'required_acceptance_scope' => (string) ($configuration['required_acceptance_scope'] ?? 'auction_terms_version'),
            'payment_submission_review_deadline_minutes' => isset($configuration['payment_submission_review_deadline_minutes'])
                ? (int) $configuration['payment_submission_review_deadline_minutes']
                : null,
            'manual_override_allowed' => (bool) ($configuration['manual_override_allowed'] ?? true),
            'manual_review_required_for_fraud' => (bool) ($configuration['manual_review_required_for_fraud'] ?? true),
            'created_by' => $createdBy,
            'finalized_at' => now(),
        ];

        $this->validator->assertValid($data);
        $data['snapshot_hash'] = $this->hasher->hash($data);

        return $data;
    }
}
