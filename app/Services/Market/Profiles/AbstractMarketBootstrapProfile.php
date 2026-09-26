<?php

declare(strict_types=1);

namespace App\Services\Market\Profiles;

use App\Contracts\Market\MarketBootstrapProfile;
use App\Services\ContentReview\Support\ProviderModelCatalog;

abstract class AbstractMarketBootstrapProfile implements MarketBootstrapProfile
{
    public function categorySourceMarketCode(): string
    {
        return 'JO';
    }

    public function supportContactSourceMarketCode(): string
    {
        return 'JO';
    }

    public function contentReviewPolicy(): array
    {
        return [
            'name' => 'سياسة مراجعة محتوى المزادات — '.strtoupper($this->marketCode()),
            'prompt_version' => 'v1',
            'result_schema_version' => 1,
            'policy' => [
                'locales' => ['ar', 'en'],
                'analyzed_text_fields' => ['title', 'description'],
                'analyze_images' => true,
                'max_images' => 4,
                'image_max_edge_px' => 1024,
                'prohibited_categories' => [
                    'weapons',
                    'drugs',
                    'counterfeit',
                    'adult',
                    'stolen_goods',
                    'live_animals',
                    'medical_claims',
                ],
                'auto_reject_categories' => [],
                'human_review_categories' => [
                    'weapons',
                    'drugs',
                    'counterfeit',
                    'live_animals',
                    'medical_claims',
                ],
                'violation_codes' => [
                    'prohibited_item',
                    'misleading_description',
                    'contact_info_in_content',
                    'price_manipulation',
                    'missing_images',
                    'image_mismatch',
                    'offensive_language',
                    'duplicate_listing',
                    'incomplete_information',
                ],
                'thresholds' => [
                    'min_confidence_approve' => 85,
                    'min_confidence_reject' => 90,
                    'grey_zone_low' => 50,
                    'grey_zone_high' => 85,
                ],
                'max_risk_level_for_auto_approve' => 'low',
                'deterministic_rules' => [
                    'min_description_length' => 30,
                    'require_at_least_one_image' => true,
                    'forbid_contact_patterns' => true,
                    'reserve_must_not_exceed_starting_multiplier' => 100,
                ],
            ],
        ];
    }

    public function contentReviewSettings(): array
    {
        $catalog = app(ProviderModelCatalog::class);
        $configured = (string) config('content_review.providers.gemini.default_model', '');
        $model = $catalog->has('gemini', $configured)
            ? $configured
            : (string) ($catalog->defaultModel('gemini') ?? $configured);

        return [
            'enabled' => true,
            'mode' => 'shadow',
            'provider' => 'gemini',
            'model' => $model,
            'timeout_seconds' => 45,
            'max_attempts' => 3,
            'backoff_seconds' => [60, 300, 900],
            'max_concurrent' => 5,
            'daily_budget_micros' => 5_000_000,
            'monthly_budget_micros' => 100_000_000,
            'max_output_tokens' => 2000,
            'analyze_images' => true,
            'circuit_breaker' => [
                'failure_threshold' => 5,
                'window_seconds' => 300,
                'open_seconds' => 600,
            ],
            'automation' => [
                'allowed_category_ids' => [],
                'max_starting_amount_minor' => $this->automationMaximumStartingAmountMinor(),
                'require_images' => true,
            ],
        ];
    }

    protected function auctionConfigurationWithAmounts(
        int $sellerDepositMinor,
        int $bidderDepositMinor,
        int $minimumBidIncrementMinor,
    ): array {
        return [
            'seller_deposit_minor' => $sellerDepositMinor,
            'bidder_deposit_minor' => $bidderDepositMinor,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'minimum_bid_increment_minor' => $minimumBidIncrementMinor,
            'extension_window_seconds' => 300,
            'extension_duration_seconds' => 600,
            'maximum_extension_count' => 6,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'non_winner_deposit_policy' => 'hold_all_eligible_bidders_until_winner_payment',
            'non_winner_deposit_hold_count' => 1,
            'alternative_winner_enabled' => true,
            'winner_default_deposit_policy' => [
                'disposition' => 'full_forfeit',
                'forfeit_amount_minor' => 0,
            ],
            'seller_deposit_policy' => [
                'unsold' => 'refund',
                'completed' => 'refund',
                'seller_cancellation_before_start' => 'refund',
                'seller_cancellation_after_start' => 'manual_review',
                'admin_cancellation_platform_fault' => 'refund',
                'admin_cancellation_seller_fault' => 'forfeit',
                'admin_cancellation_neutral' => 'refund',
                'admin_cancellation_fraud_or_compliance' => 'manual_review',
                'system_cancellation_platform_fault' => 'refund',
                'system_cancellation_seller_fault' => 'forfeit',
                'system_cancellation_neutral' => 'refund',
                'winner_default' => 'keep_held',
                'seller_breach' => 'forfeit',
                'dispute_complete' => 'refund',
                'dispute_cancel' => 'manual_review',
                'dispute_resume_handover' => 'keep_held',
            ],
        ];
    }

    /** @return list<string> */
    protected function allPaymentPurposes(): array
    {
        return ['seller_deposit', 'bidder_deposit', 'winner_settlement'];
    }

    abstract protected function automationMaximumStartingAmountMinor(): int;
}
