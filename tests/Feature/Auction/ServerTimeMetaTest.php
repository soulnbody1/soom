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
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\AcceptsAuctionTerms;
use Tests\TestCase;

final class ServerTimeMetaTest extends TestCase
{
    use AcceptsAuctionTerms;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_auction_list_carries_server_time(): void
    {
        $this->auction();

        $meta = $this->getJson('/api/auctions')->assertOk()->json('meta');

        $this->assertArrayHasKey('server_time', $meta);
        $this->assertNotNull(strtotime((string) $meta['server_time']));
    }

    public function test_auction_details_carry_server_time_for_guests_and_users(): void
    {
        $auction = $this->auction();

        $this->assertNotNull(
            $this->getJson('/api/auctions/'.$auction->public_id)->assertOk()->json('meta.server_time')
        );

        $this->assertNotNull(
            $this->actingAs($this->user(), 'sanctum')
                ->getJson('/api/auctions/'.$auction->public_id)
                ->assertOk()
                ->json('meta.server_time')
        );
    }

    public function test_state_changing_responses_carry_server_time(): void
    {
        $auction = $this->auction();
        $bidder = $this->user();

        $this->assertNotNull(
            $this->actingAs($bidder, 'sanctum')
                ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
                ->assertCreated()
                ->json('meta.server_time')
        );

        $this->assertNotNull(
            $this->actingAs($bidder, 'sanctum')
                ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
                ->assertCreated()
                ->json('meta.server_time')
        );
    }

    public function test_error_responses_also_carry_server_time(): void
    {
        $auction = $this->auction();

        $meta = $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')
            ->assertStatus(422)
            ->json('meta');

        $this->assertArrayHasKey('server_time', $meta);
    }

    public function test_my_lists_carry_server_time(): void
    {
        $this->auction();

        $this->assertNotNull(
            $this->actingAs($this->user(), 'sanctum')
                ->getJson('/api/soom/my/bids')
                ->assertOk()
                ->json('meta.server_time')
        );
    }

    private function auction(): Auction
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $configuration = AuctionConfigurationVersion::create([
            'version_number' => ((int) AuctionConfigurationVersion::max('version_number')) + 1,
            'configuration' => [
                'seller_deposit_minor' => 2_000,
                'bidder_deposit_minor' => 10_000,
                'minimum_bid_increment_minor' => 500,
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
                'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
            ],
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'meta-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create(['name' => 'meta-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Meta auction '.Str::ulid(),
            'description' => 'Meta auction.',
            'status' => AuctionStatus::Live,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->subDay(),
            'original_ends_at' => now()->addDay(),
            'ends_at' => now()->addDay(),
        ]);

        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction->refresh(), $seller->id);

        return $auction;
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "meta-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
