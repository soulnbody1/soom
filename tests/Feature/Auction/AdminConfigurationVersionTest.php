<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminConfigurationVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_index_lists_versions_newest_first_without_full_configuration(): void
    {
        $creator = $this->user('admin');
        $this->seedVersion(1, true, $creator->id);
        $this->seedVersion(2, true, $creator->id);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/configuration-versions')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['version_number']);
        $this->assertSame(1, $rows[1]['version_number']);
        $this->assertSame($creator->id, $rows[0]['created_by']['id']);
        $this->assertArrayNotHasKey('configuration', $rows[0]);
        $this->assertTrue($rows[0]['is_active']);
        $this->assertNotNull($rows[0]['published_at']);
    }

    public function test_show_returns_full_configuration_payload(): void
    {
        $version = $this->seedVersion(3, true, $this->user('admin')->id);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/configuration-versions/'.$version->public_id)
            ->assertOk();

        $response->assertJsonPath('data.id', $version->public_id);
        $response->assertJsonPath('data.version_number', 3);
        $this->assertSame(10_000, $response->json('data.configuration.seller_deposit_minor'));
        $this->assertSame(
            config('auction.seller_deposit_policy')['unsold'],
            $response->json('data.configuration.seller_deposit_policy.unsold')
        );
    }

    public function test_create_and_activate_supersedes_for_new_auctions_without_touching_existing_versions(): void
    {
        $old = $this->seedVersion(1, true, $this->user('admin')->id);
        $oldConfiguration = $old->configuration;

        $payload = $this->validPayload(['seller_deposit_minor' => 9_999]);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson('/api/admin/auctions/configuration-versions', $payload)
            ->assertCreated();

        $response->assertJsonPath('data.version_number', 2);
        $response->assertJsonPath('data.is_active', true);
        $this->assertSame(9_999, $response->json('data.configuration.seller_deposit_minor'));

        // New auctions resolve the new version.
        $active = app(AuctionConfigurationRepository::class)->getActiveConfiguration();
        $this->assertSame(2, $active->version_number);
        $this->assertSame(9_999, $active->seller_deposit_minor);

        // The old version row is untouched (append-only).
        $old->refresh();
        $this->assertTrue($old->is_active);
        $this->assertSame($oldConfiguration, $old->configuration);
    }

    public function test_draft_version_is_not_resolved_as_active(): void
    {
        $this->seedVersion(1, true, $this->user('admin')->id);

        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson('/api/admin/auctions/configuration-versions', $this->validPayload() + ['publish' => false])
            ->assertCreated()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.published_at', null);

        $active = app(AuctionConfigurationRepository::class)->getActiveConfiguration();
        $this->assertSame(1, $active->version_number);
    }

    public function test_validation_rejects_missing_policy_keys_and_bad_enums(): void
    {
        $admin = $this->user('admin');

        $missingKey = $this->validPayload();
        unset($missingKey['configuration']['seller_deposit_policy']['dispute_cancel']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/auctions/configuration-versions', $missingKey)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['configuration.seller_deposit_policy.dispute_cancel']);

        $badEnum = $this->validPayload();
        $badEnum['configuration']['seller_deposit_policy']['unsold'] = 'burn_it';

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/auctions/configuration-versions', $badEnum)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['configuration.seller_deposit_policy.unsold']);

        $badDisposition = $this->validPayload();
        $badDisposition['configuration']['winner_default_deposit_policy']['disposition'] = 'confiscate_everything';

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/auctions/configuration-versions', $badDisposition)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['configuration.winner_default_deposit_policy.disposition']);
    }

    public function test_non_admin_cannot_access_configuration_versions(): void
    {
        $version = $this->seedVersion(1, true, $this->user('admin')->id);

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/admin/auctions/configuration-versions')
            ->assertForbidden();

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/admin/auctions/configuration-versions/'.$version->public_id)
            ->assertForbidden();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/admin/auctions/configuration-versions', $this->validPayload())
            ->assertForbidden();
    }

    // ─── Fixtures ──────────────────────────────────────────────

    private function validPayload(array $configurationOverrides = []): array
    {
        return [
            'configuration' => array_replace([
                'seller_deposit_minor' => 10_000,
                'bidder_deposit_minor' => 5_000,
                'platform_fee_type' => 'percentage',
                'platform_fee_basis_points' => 250,
                'platform_fee_fixed_minor' => 0,
                'minimum_bid_increment_minor' => 1_000,
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
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
            ], $configurationOverrides),
        ];
    }

    private function seedVersion(int $number, bool $active, int $creatorId): AuctionConfigurationVersion
    {
        return AuctionConfigurationVersion::create([
            'version_number' => $number,
            'configuration' => $this->validPayload()['configuration'],
            'is_active' => $active,
            'created_by' => $creatorId,
            'published_at' => $active ? now()->subDay() : null,
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "config-version-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
