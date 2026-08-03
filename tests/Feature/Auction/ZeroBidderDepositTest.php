<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentSubmission;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZeroBidderDepositTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_registration_alone_does_not_qualify_before_terms_are_accepted(): void
    {
        $auction = $this->zeroDepositAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register')
            ->assertCreated();

        $participant = AuctionParticipant::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();

        $this->assertSame(AuctionParticipantStatus::Registered, $participant->status);
        $this->assertNull($participant->qualified_at);
    }

    public function test_accepting_terms_auto_qualifies_when_no_deposit_is_required(): void
    {
        $auction = $this->zeroDepositAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register')
            ->assertCreated();
        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')
            ->assertCreated();

        $participant = AuctionParticipant::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();

        $this->assertSame(AuctionParticipantStatus::Qualified, $participant->status);
        $this->assertNotNull($participant->qualified_at);
    }

    public function test_repeated_terms_acceptance_is_idempotent(): void
    {
        $auction = $this->zeroDepositAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/register')->assertCreated();
        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')->assertCreated();

        $first = AuctionParticipant::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();
        $qualifiedAt = $first->qualified_at;

        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')->assertCreated();

        $second = AuctionParticipant::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();

        $this->assertSame(AuctionParticipantStatus::Qualified, $second->status);
        $this->assertEquals($qualifiedAt, $second->qualified_at);
        $this->assertSame(1, AuctionParticipant::where('auction_id', $auction->id)->count());
    }

    public function test_bidding_is_blocked_before_terms_and_allowed_after(): void
    {
        $auction = $this->zeroDepositAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/register')->assertCreated();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/bids', [
                'amount' => '100.000',
                'currency_code' => 'JOD',
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'terms_required_before_bidding');

        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')->assertCreated();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/bids', [
                'amount' => '100.000',
                'currency_code' => 'JOD',
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertCreated();
    }

    public function test_no_deposit_or_payment_records_are_created(): void
    {
        $auction = $this->zeroDepositAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/register')->assertCreated();
        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')->assertCreated();

        $this->assertSame(0, AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->count());
        $this->assertSame(0, PaymentSubmission::where('auction_id', $auction->id)->where('user_id', $bidder->id)->count());
    }

    public function test_bidder_deposit_submission_is_still_rejected_for_zero_deposit_auctions(): void
    {
        $auction = $this->zeroDepositAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/register')->assertCreated();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/bidder-deposit', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_non_zero_deposit_auction_is_not_auto_qualified(): void
    {
        $auction = $this->zeroDepositAuction(1_000);
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/register')->assertCreated();
        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')->assertCreated();

        $participant = AuctionParticipant::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();

        $this->assertSame(AuctionParticipantStatus::Registered, $participant->status);
        $this->assertNull($participant->qualified_at);
    }

    private function zeroDepositAuction(int $bidderDepositMinor = 0): Auction
    {
        $terms = AuctionTermsVersion::firstOrCreate(
            ['version_number' => 1],
            ['title' => 'Terms', 'body' => 'Auction terms.', 'is_active' => true, 'published_at' => now()->subDay()]
        );
        $configuration = AuctionConfigurationVersion::firstOrCreate(
            ['version_number' => $bidderDepositMinor === 0 ? 1 : 2],
            [
                'configuration' => [
                    'seller_deposit_minor' => 2_000,
                    'bidder_deposit_minor' => $bidderDepositMinor,
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
        $category = Category::create(['name' => 'zero-dep-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'zero-dep-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Zero deposit auction '.Str::ulid(),
            'description' => 'Zero deposit auction.',
            'status' => AuctionStatus::Live,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => $bidderDepositMinor,
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
            'email' => "zero-dep-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
