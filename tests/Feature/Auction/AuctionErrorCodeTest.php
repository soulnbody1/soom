<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuctionErrorCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_domain_error_returns_stable_code_with_unchanged_arabic_message(): void
    {
        $auction = $this->auction(AuctionStatus::Live);
        $token = $this->user()->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')
            ->assertStatus(422);

        $response->assertJson([
            'success' => false,
            'code' => 'terms_registration_required',
            'message' => __('auction.errors.terms_registration_required'),
        ]);
    }

    public function test_domain_error_code_is_independent_of_message_text(): void
    {
        $auction = $this->auction(AuctionStatus::Live);
        $token = $this->user()->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/bids', [
                'amount' => '100.000',
                'currency_code' => 'JOD',
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'bidder_not_qualified');
    }

    public function test_validation_error_carries_stable_code_and_preserves_errors_map(): void
    {
        $auction = $this->auction(AuctionStatus::Live);
        $token = $this->user()->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/bids', [])
            ->assertStatus(422);

        $response->assertJsonPath('code', 'validation_failed');
        $response->assertJsonPath('success', false);
        $response->assertJsonValidationErrors(['amount', 'currency_code', 'idempotency_key']);
    }

    public function test_unauthenticated_request_returns_stable_code(): void
    {
        $auction = $this->auction(AuctionStatus::Live);

        $this->postJson('/api/soom/auctions/'.$auction->public_id.'/register')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonPath('success', false);
    }

    public function test_missing_auction_returns_auction_not_found_code(): void
    {
        $token = $this->user()->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/soom/auctions/01JQZZZZZZZZZZZZZZZZZZZZZZ/register')
            ->assertStatus(404)
            ->assertJsonPath('code', 'auction_not_found')
            ->assertJsonPath('message', __('auction.errors.auction_not_found'));
    }

    public function test_forbidden_request_returns_forbidden_code(): void
    {
        $auction = $this->auction(AuctionStatus::Live);
        $token = $this->user()->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/auctions/'.$auction->public_id.'/review', [
                'action' => 'approve',
                'reason' => 'ok',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    private function auction(AuctionStatus $status): Auction
    {
        $terms = AuctionTermsVersion::firstOrCreate(
            ['version_number' => 1],
            ['title' => 'Terms', 'body' => 'Auction terms.', 'is_active' => true, 'published_at' => now()->subDay()]
        );
        $configuration = AuctionConfigurationVersion::firstOrCreate(
            ['version_number' => 1],
            [
                'configuration' => [
                    'seller_deposit_minor' => 2_000,
                    'bidder_deposit_minor' => 1_000,
                    'minimum_bid_increment_minor' => 500,
                    'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                    'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
                    'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                    'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
                ],
                'is_active' => true,
                'published_at' => now()->subDay(),
            ]
        );
        $category = Category::create(['name' => 'error-code-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'error-code-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Error code auction '.Str::ulid(),
            'description' => 'Error code auction.',
            'status' => $status,
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
            'starts_at' => now()->subDay(),
            'original_ends_at' => now()->addDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->snapshotApprovedAuction($auction);

        return $auction;
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "error-code-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
