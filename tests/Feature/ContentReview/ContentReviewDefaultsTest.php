<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Models\ContentReview\ContentReviewSetting;
use App\Services\ContentReview\Support\ReviewModeResolver;
use Database\Seeders\ContentReviewSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ContentReviewDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_the_shipped_configuration_keeps_the_subsystem_off(): void
    {
        $config = require base_path('config/content_review.php');

        $this->assertFalse($config['enabled']);
        $this->assertSame('manual', $config['default_mode']);
    }

    public function test_the_seeder_publishes_a_disabled_manual_configuration(): void
    {
        app(ContentReviewSeeder::class)->run();

        $settings = ContentReviewSetting::where('scope', ReviewableSubjectType::Auction->value)
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertFalse($settings->settings['enabled']);
        $this->assertSame('manual', $settings->settings['mode']);
        $this->assertSame([], $settings->settings['automation']['allowed_category_ids']);
    }

    public function test_the_seeded_policy_rejects_nothing_automatically(): void
    {
        app(ContentReviewSeeder::class)->run();

        $policy = ContentReviewPolicy::where('subject_type', ReviewableSubjectType::Auction->value)
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertSame([], $policy->policy['auto_reject_categories']);
        $this->assertSame('low', $policy->policy['max_risk_level_for_auto_approve']);
    }

    public function test_the_resolved_mode_stays_manual_after_seeding(): void
    {
        app(ContentReviewSeeder::class)->run();

        $this->assertSame(
            ReviewMode::Manual,
            app(ReviewModeResolver::class)->resolve(ReviewableSubjectType::Auction)
        );
    }

    public function test_the_master_switch_forces_manual_even_when_automatic_is_published(): void
    {
        ContentReviewSetting::create([
            'scope' => ReviewableSubjectType::Auction->value,
            'version_number' => 1,
            'is_active' => true,
            'published_at' => now(),
            'settings' => ['enabled' => true, 'mode' => ReviewMode::AiAutomatic->value],
        ]);

        config()->set('content_review.enabled', false);

        $this->assertSame(
            ReviewMode::Manual,
            app(ReviewModeResolver::class)->resolve(ReviewableSubjectType::Auction)
        );
    }
}
