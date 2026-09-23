<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UserAuctionResourceShapeTest extends TestCase
{
    private const EXPECTED_KEYS = [
        'category', 'currency_code', 'current_amount', 'current_dispute', 'description',
        'handover_status', 'id', 'images', 'location', 'market_code', 'metrics', 'minimum_next_bid', 'my_bids',
        'my_deposits', 'my_participation', 'my_payment_submissions', 'my_refunds', 'next_action',
        'participation_requirements', 'reserve_met', 'seller', 'seller_context', 'starting_amount',
        'status', 'status_label', 'timeline', 'title', 'url', 'winner_settlement',
    ];

    private const PARTICIPATION_KEYS = [
        'blocking_reason', 'can_bid', 'deposit_required', 'deposit_status', 'is_highest_bidder',
        'is_registered', 'is_seller', 'is_winner', 'my_bids_count', 'my_highest_bid',
        'participant_status', 'qualified_at', 'registered_at', 'required_terms_version_id',
        'terms_accepted', 'terms_accepted_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_every_viewer_receives_an_identical_key_set(): void
    {
        [$auction, $seller, $winner, $qualified, $registered] = $this->fullAuction();
        $stranger = $this->user();
        $admin = $this->user('admin');

        $shapes = [];
        foreach ([
            'guest' => null,
            'stranger' => $stranger,
            'registered' => $registered,
            'qualified' => $qualified,
            'winner' => $winner,
            'seller' => $seller,
            'admin' => $admin,
        ] as $label => $viewer) {
            $data = $this->request($viewer, $auction);
            $keys = array_keys($data);
            sort($keys);
            $shapes[$label] = $keys;
        }

        foreach ($shapes as $label => $keys) {
            $this->assertSame(self::EXPECTED_KEYS, $keys, "Viewer [{$label}] returned a different key set");
        }
    }

    public function test_my_participation_block_is_always_present_with_a_fixed_shape(): void
    {
        [$auction, $seller, $winner, $qualified, $registered] = $this->fullAuction();

        foreach ([null, $this->user(), $registered, $qualified, $winner, $seller] as $viewer) {
            $participation = $this->request($viewer, $auction)['my_participation'];
            $keys = array_keys($participation);
            sort($keys);

            $this->assertSame(self::PARTICIPATION_KEYS, $keys);
        }
    }

    public function test_inapplicable_blocks_are_null_and_lists_are_empty_rather_than_absent(): void
    {
        [$auction] = $this->fullAuction();

        $data = $this->request(null, $auction);

        $this->assertNull($data['winner_settlement']);
        $this->assertNull($data['seller_context']);
        $this->assertNull($data['handover_status']);
        $this->assertNull($data['current_dispute']);
        $this->assertSame([], $data['my_bids']);
        $this->assertSame([], $data['my_deposits']);
        $this->assertSame([], $data['my_payment_submissions']);
        $this->assertSame([], $data['my_refunds']);
    }

    public function test_each_viewer_sees_their_own_participation_facts(): void
    {
        [$auction, $seller, $winner, $qualified, $registered] = $this->fullAuction();

        $sellerState = $this->request($seller, $auction)['my_participation'];
        $this->assertTrue($sellerState['is_seller']);
        $this->assertFalse($sellerState['can_bid']);

        $winnerState = $this->request($winner, $auction)['my_participation'];
        $this->assertTrue($winnerState['is_winner']);

        $registeredState = $this->request($registered, $auction)['my_participation'];
        $this->assertTrue($registeredState['is_registered']);
        $this->assertSame('registered', $registeredState['participant_status']);
        $this->assertFalse($registeredState['is_winner']);

        $qualifiedState = $this->request($qualified, $auction)['my_participation'];
        $this->assertSame('qualified', $qualifiedState['participant_status']);
        $this->assertTrue($qualifiedState['terms_accepted']);
    }

    public function test_participation_requirements_expose_the_deposit_before_any_record_exists(): void
    {
        [$auction] = $this->fullAuction();

        $requirements = $this->request($this->user(), $auction)['participation_requirements'];

        $this->assertSame(10_000, $requirements['bidder_deposit_required']['minor']);
        $this->assertSame(500, $requirements['minimum_bid_increment']['minor']);
        $this->assertSame(48, $requirements['winner_payment_deadline_hours']);
        $this->assertSame(72, $requirements['handover_deadline_hours']);
        $this->assertNotNull($requirements['registration_deadline']);
        $this->assertNotNull($requirements['deposit_deadline']);
        $this->assertTrue($requirements['auto_extend']['enabled']);
    }

    private function request(?User $viewer, Auction $auction): array
    {
        $request = $viewer === null ? $this : $this->actingAs($viewer, 'sanctum');

        return $request->getJson('/api/auctions/'.$auction->public_id)->assertOk()->json('data');
    }

    private function fullAuction(): array
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
        $category = Category::create(['name' => 'shape-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'shape-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Shape auction '.Str::ulid(),
            'description' => 'Shape auction.',
            'status' => AuctionStatus::HandoverPending,
            'starting_amount_minor' => 50_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->subDays(2),
            'original_ends_at' => now()->subHour(),
            'ends_at' => now()->subHour(),
        ]);
        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction->refresh(), $seller->id);

        $winner = $this->user();
        $winnerParticipant = $this->participant($auction, $winner, AuctionParticipantStatus::Qualified);
        $this->acceptTerms($auction, $winnerParticipant, $winner);
        $this->deposit($auction, $winnerParticipant, $winner, AuctionDepositStatus::AppliedToSettlement);
        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $winnerParticipant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'shape-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);
        $auction->forceFill(['winning_bid_id' => $bid->id, 'current_leading_bid_id' => $bid->id])->save();

        AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $winner->id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::HandoverPending,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 10_000,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 90_000,
            'amount_paid_minor' => 90_000,
            'remaining_amount_minor' => 0,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->subHour(),
            'paid_at' => now()->subHour(),
            'handover_due_at' => now()->addDay(),
        ]);

        $qualified = $this->user();
        $qualifiedParticipant = $this->participant($auction, $qualified, AuctionParticipantStatus::Qualified);
        $this->acceptTerms($auction, $qualifiedParticipant, $qualified);
        $this->deposit($auction, $qualifiedParticipant, $qualified, AuctionDepositStatus::Held);

        $registered = $this->user();
        $this->participant($auction, $registered, AuctionParticipantStatus::Registered);

        return [$auction, $seller, $winner, $qualified, $registered];
    }

    private function participant(Auction $auction, User $user, AuctionParticipantStatus $status): AuctionParticipant
    {
        return AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => $status,
            'registered_at' => now()->subDay(),
            'qualified_at' => $status === AuctionParticipantStatus::Qualified ? now()->subHour() : null,
        ]);
    }

    private function acceptTerms(Auction $auction, AuctionParticipant $participant, User $user): void
    {
        AuctionTermsAcceptance::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'terms_version_id' => $auction->terms_version_id,
            'accepted_at' => now()->subHour(),
        ]);
    }

    private function deposit(Auction $auction, AuctionParticipant $participant, User $user, AuctionDepositStatus $status): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => $status,
            'required_amount_minor' => 10_000,
            'held_amount_minor' => 10_000,
            'applied_amount_minor' => $status === AuctionDepositStatus::AppliedToSettlement ? 10_000 : 0,
            'currency_code' => 'JOD',
            'held_at' => now()->subHour(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "shape-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
