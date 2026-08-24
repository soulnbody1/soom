<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\ContentReview\ContentReviewSetting;
use App\Services\ContentReview\Actions\PublishContentReviewSettingsAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class ContentReviewSettingsApiTest extends TestCase
{
    use BuildsContentReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config()->set('content_review.enabled', true);
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_the_settings_endpoint_returns_the_active_version_and_the_resolved_mode(): void
    {
        $this->publishSettings(ReviewMode::Shadow);

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson('/api/admin/content-review/settings?scope=auction')
            ->assertOk()
            ->assertJsonPath('data.scope', 'auction')
            ->assertJsonPath('data.master_switch_enabled', true)
            ->assertJsonPath('data.active.settings.mode', ReviewMode::Shadow->value)
            ->assertJsonPath('data.subjects.auction.resolved_mode', ReviewMode::Shadow->value);
    }

    public function test_the_master_switch_forces_manual_regardless_of_the_published_mode(): void
    {
        config()->set('content_review.enabled', false);
        $this->publishSettings(ReviewMode::AiAutomatic);

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson('/api/admin/content-review/settings?scope=auction')
            ->assertOk()
            ->assertJsonPath('data.master_switch_enabled', false)
            ->assertJsonPath('data.active.settings.mode', ReviewMode::AiAutomatic->value)
            ->assertJsonPath('data.subjects.auction.resolved_mode', ReviewMode::Manual->value);
    }

    public function test_publishing_appends_a_version_and_deactivates_the_previous_one(): void
    {
        $first = $this->publishSettings(ReviewMode::Manual);

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson('/api/admin/content-review/settings', [
                'scope' => 'auction',
                'settings' => $this->settingsPayload(ReviewMode::Shadow),
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_number', $first->version_number + 1)
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse((bool) $first->refresh()->is_active);
        $this->assertSame(1, ContentReviewSetting::where('scope', 'auction')->where('is_active', true)->count());
        $this->assertSame(2, ContentReviewSetting::where('scope', 'auction')->count());
    }

    public function test_a_published_settings_version_can_never_be_edited(): void
    {
        $setting = $this->publishSettings(ReviewMode::Shadow);

        $this->expectExceptionMessage(__('content_review.errors.settings_in_use'));

        $setting->forceFill(['settings' => ['enabled' => true, 'mode' => 'ai_automatic']])->save();
    }

    public function test_two_concurrent_publishes_never_leave_two_active_versions(): void
    {
        $this->publishSettings(ReviewMode::Manual);

        $lock = Cache::lock('content_review:publish:settings:auction', 10);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
                ->postJson('/api/admin/content-review/settings', [
                    'scope' => 'auction',
                    'settings' => $this->settingsPayload(ReviewMode::Shadow),
                ])
                ->assertStatus(409)
                ->assertJsonPath('code', 'version_conflict');
        } finally {
            $lock->release();
        }

        $this->assertSame(1, ContentReviewSetting::where('scope', 'auction')->where('is_active', true)->count());
    }

    public function test_sequential_publishes_always_leave_exactly_one_active_version(): void
    {
        $action = app(PublishContentReviewSettingsAction::class);

        foreach ([ReviewMode::Manual, ReviewMode::Shadow, ReviewMode::AiAssisted] as $mode) {
            $action->execute('auction', $this->settingsPayload($mode), null);
        }

        $this->assertSame(1, ContentReviewSetting::where('scope', 'auction')->where('is_active', true)->count());
        $this->assertSame(3, ContentReviewSetting::where('scope', 'auction')->count());
        $this->assertSame(
            ReviewMode::AiAssisted->value,
            ContentReviewSetting::where('scope', 'auction')->where('is_active', true)->firstOrFail()->settings['mode']
        );
    }

    public function test_an_unknown_setting_key_is_dropped_before_it_is_stored(): void
    {
        $payload = $this->settingsPayload(ReviewMode::Shadow);
        $payload['anthropic_api_key'] = 'sk-ant-should-never-persist';
        $payload['unexpected_flag'] = true;

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson('/api/admin/content-review/settings', ['scope' => 'auction', 'settings' => $payload])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        unset($payload['anthropic_api_key']);

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson('/api/admin/content-review/settings', ['scope' => 'auction', 'settings' => $payload])
            ->assertCreated();

        $stored = (array) ContentReviewSetting::where('scope', 'auction')->where('is_active', true)->firstOrFail()->settings;

        $this->assertArrayNotHasKey('unexpected_flag', $stored);
        $this->assertArrayNotHasKey('anthropic_api_key', $stored);
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_are_rejected(array $overrides): void
    {
        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson('/api/admin/content-review/settings', [
                'scope' => 'auction',
                'settings' => array_replace($this->settingsPayload(ReviewMode::Shadow), $overrides),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }

    public static function invalidSettings(): array
    {
        return [
            'unknown provider' => [['provider' => 'not-a-provider']],
            'unpriced model' => [['model' => 'gpt-9']],
            'a model that belongs to another provider' => [['provider' => 'openrouter', 'model' => 'claude-sonnet-5']],
            'unknown mode' => [['mode' => 'fully_autonomous']],
            'timeout below the floor' => [['timeout_seconds' => 1]],
            'zero attempts' => [['max_attempts' => 0]],
            'daily budget above monthly' => [['daily_budget_micros' => 10, 'monthly_budget_micros' => 5]],
            'an active mode while disabled' => [['enabled' => false, 'mode' => 'ai_assisted']],
            'negative budget' => [['daily_budget_micros' => -1]],
        ];
    }

    public function test_a_model_is_validated_against_the_provider_being_published(): void
    {
        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson('/api/admin/content-review/settings', [
                'scope' => 'auction',
                'settings' => array_replace($this->settingsPayload(ReviewMode::Shadow), [
                    'provider' => 'openrouter',
                    'model' => 'claude-sonnet-5',
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson('/api/admin/content-review/settings', [
                'scope' => 'auction',
                'settings' => array_replace($this->settingsPayload(ReviewMode::Shadow), [
                    'provider' => 'openrouter',
                    'model' => 'google/gemini-2.5-flash',
                ]),
            ])
            ->assertStatus(201);

        $stored = (array) ContentReviewSetting::where('scope', 'auction')->where('is_active', true)->firstOrFail()->settings;

        $this->assertSame('openrouter', $stored['provider']);
        $this->assertSame('google/gemini-2.5-flash', $stored['model']);
    }

    public function test_an_unknown_scope_is_rejected(): void
    {
        $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->postJson('/api/admin/content-review/settings', [
                'scope' => 'advertisement',
                'settings' => $this->settingsPayload(ReviewMode::Shadow),
            ])
            ->assertStatus(422);
    }

    public function test_the_versions_endpoint_lists_every_published_version(): void
    {
        $this->publishSettings(ReviewMode::Manual);
        $this->publishSettings(ReviewMode::Shadow);

        $response = $this->actingAs($this->fullyPermittedAdmin(), 'sanctum')
            ->getJson('/api/admin/content-review/settings/versions?scope='.ReviewableSubjectType::Auction->value)
            ->assertOk();

        $this->assertCount(2, (array) $response->json('data'));
        $this->assertSame(2, $response->json('data.0.version_number'));
    }
}
