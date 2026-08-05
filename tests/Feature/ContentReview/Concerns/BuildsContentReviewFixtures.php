<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview\Concerns;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionMedia;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Models\ContentReview\ContentReviewSetting;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\SubmitAuctionForReviewAction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait BuildsContentReviewFixtures
{
    protected function admin(array $permissions = []): User
    {
        return $this->makeUser('admin', $permissions);
    }

    protected function seller(): User
    {
        return $this->makeUser('user');
    }

    protected function makeUser(string $role, array $permissions = []): User
    {
        $unique = strtolower((string) Str::ulid());

        return User::create([
            'name' => 'Content Review '.$role,
            'email' => "cr-{$unique}@example.test",
            'phone' => '+96279'.str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
            'role' => $role,
            'auction_permissions' => $permissions === [] ? null : $permissions,
        ]);
    }

    protected function fullyPermittedAdmin(): User
    {
        return $this->admin((array) config('content_review.admin_permissions'));
    }

    protected function auctionReviewer(array $extra = []): User
    {
        return $this->admin(array_merge(['auction.review', 'auction.approve'], $extra));
    }

    protected function publishPolicy(array $overrides = []): ContentReviewPolicy
    {
        return ContentReviewPolicy::create([
            'subject_type' => ReviewableSubjectType::Auction->value,
            'version_number' => ((int) ContentReviewPolicy::max('version_number')) + 1,
            'name' => 'Test policy',
            'prompt_version' => 'v1',
            'result_schema_version' => 1,
            'is_active' => true,
            'published_at' => now(),
            'policy' => array_replace($this->policyPayload(), $overrides),
        ]);
    }

    protected function policyPayload(): array
    {
        return [
            'locales' => ['ar', 'en'],
            'analyzed_text_fields' => ['title', 'description'],
            'analyze_images' => true,
            'max_images' => 4,
            'image_max_edge_px' => 1024,
            'prohibited_categories' => ['weapons', 'drugs', 'counterfeit', 'adult'],
            'auto_reject_categories' => [],
            'human_review_categories' => ['counterfeit'],
            'violation_codes' => ['prohibited_item', 'misleading_description', 'contact_info_in_content'],
            'thresholds' => [
                'min_confidence_approve' => 85,
                'min_confidence_reject' => 90,
                'grey_zone_low' => 50,
                'grey_zone_high' => 85,
            ],
            'max_risk_level_for_auto_approve' => 'low',
            'deterministic_rules' => [
                'min_description_length' => 30,
                'require_at_least_one_image' => false,
                'forbid_contact_patterns' => true,
                'reserve_must_not_exceed_starting_multiplier' => 100,
            ],
        ];
    }

    protected function publishSettings(ReviewMode $mode, array $overrides = []): ContentReviewSetting
    {
        ContentReviewSetting::where('scope', ReviewableSubjectType::Auction->value)
            ->where('is_active', true)
            ->get()
            ->each(fn (ContentReviewSetting $setting) => $setting->forceFill(['is_active' => false])->save());

        return ContentReviewSetting::create([
            'scope' => ReviewableSubjectType::Auction->value,
            'version_number' => ((int) ContentReviewSetting::max('version_number')) + 1,
            'is_active' => true,
            'published_at' => now(),
            'settings' => array_replace($this->settingsPayload($mode), $overrides),
        ]);
    }

    protected function settingsPayload(ReviewMode $mode): array
    {
        return [
            'enabled' => $mode !== ReviewMode::Manual,
            'mode' => $mode->value,
            'provider' => 'fake',
            'model' => 'claude-sonnet-5',
            'timeout_seconds' => 45,
            'max_attempts' => 3,
            'backoff_seconds' => [60, 300, 900],
            'max_concurrent' => 5,
            'max_output_tokens' => 2000,
            'daily_budget_micros' => 5_000_000,
            'monthly_budget_micros' => 100_000_000,
            'analyze_images' => true,
            'circuit_breaker' => ['failure_threshold' => 5, 'window_seconds' => 300, 'open_seconds' => 600],
            'automation' => [
                'allowed_category_ids' => [],
                'max_starting_amount_minor' => 100_000,
                'require_images' => true,
            ],
        ];
    }

    protected function draftAuction(array $overrides = []): Auction
    {
        $version = $this->auctionConfigurationVersion();

        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Content review terms body '.Str::ulid(),
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        return Auction::create(array_replace([
            'seller_id' => $this->seller()->id,
            'category_id' => Category::create(['name' => 'cr-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create([
                'name' => 'cr-country-'.Str::ulid(),
                'code' => strtoupper(substr((string) Str::ulid(), 0, 6)),
            ])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $version->id,
            'currency_code' => 'JOD',
            'title' => 'A well described collectible item for sale',
            'description' => 'A carefully written description of the collectible item, its condition and its provenance.',
            'status' => AuctionStatus::Draft,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 1_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->addDay(),
            'original_ends_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3),
        ], $overrides));
    }

    protected function attachMedia(Auction $auction): AuctionMedia
    {
        return AuctionMedia::create([
            'auction_id' => $auction->id,
            'disk' => 'public',
            'path' => 'auction-media/'.Str::ulid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12_345,
            'sort_order' => 0,
        ]);
    }

    protected function submitForReview(?Auction $auction = null): Auction
    {
        $auction ??= $this->draftAuction();

        return app(SubmitAuctionForReviewAction::class)->execute($auction, (int) $auction->seller_id);
    }

    protected function cleanResultPayload(): array
    {
        return [
            'recommendation' => 'approve',
            'confidence' => 96,
            'risk_level' => 'low',
            'requires_human_review' => false,
            'summary_ar' => 'الإعلان مطابق لسياسة المحتوى.',
            'summary_en' => 'The listing matches the content policy.',
            'categories' => [],
            'violations' => [],
            'findings' => [],
            'policy_checks' => [],
            'missing_information' => [],
        ];
    }
}
