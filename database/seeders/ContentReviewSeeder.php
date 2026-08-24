<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Models\ContentReview\ContentReviewSetting;
use App\Services\ContentReview\Support\ProviderModelCatalog;
use Illuminate\Database\Seeder;

final class ContentReviewSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPolicy();
        $this->seedSettings();
    }

    private function seedPolicy(): void
    {
        $subjectType = ReviewableSubjectType::Auction->value;

        if (ContentReviewPolicy::where('subject_type', $subjectType)->exists()) {
            return;
        }

        ContentReviewPolicy::create([
            'subject_type' => $subjectType,
            'version_number' => 1,
            'name' => 'Auction content policy v1',
            'prompt_version' => 'v1',
            'result_schema_version' => 1,
            'is_active' => true,
            'published_at' => now(),
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
                'human_review_categories' => ['counterfeit', 'medical_claims', 'high_value'],
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
        ]);
    }

    private function seedSettings(): void
    {
        $scope = ReviewableSubjectType::Auction->value;

        if (ContentReviewSetting::where('scope', $scope)->exists()) {
            return;
        }

        $catalog = app(ProviderModelCatalog::class);
        $provider = (string) config('content_review.provider', 'fake');
        $configuredModel = (string) config('content_review.model', '');
        $model = $catalog->has($provider, $configuredModel)
            ? $configuredModel
            : (string) ($catalog->defaultModel($provider) ?? $configuredModel);

        ContentReviewSetting::create([
            'scope' => $scope,
            'version_number' => 1,
            'is_active' => true,
            'published_at' => now(),
            'settings' => [
                'enabled' => false,
                'mode' => 'manual',
                'provider' => $provider,
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
                    'max_starting_amount_minor' => 100_000,
                    'require_images' => true,
                ],
            ],
        ]);
    }
}
