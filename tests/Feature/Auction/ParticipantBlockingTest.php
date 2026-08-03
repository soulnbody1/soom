<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ParticipantBlockingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_admin_blocks_a_participant_with_a_reason_and_audit_entry(): void
    {
        [$auction, $participant] = $this->auctionWithParticipant();

        $data = $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson($this->blockUrl($auction, $participant), ['reason' => 'suspicious bidding'])
            ->assertOk()
            ->json('data');

        $this->assertSame('blocked', $data['status']);
        $this->assertSame('suspicious bidding', $participant->refresh()->block_reason);
        $this->assertNotNull($participant->blocked_at);
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)
            ->where('event_type', 'auction.participant_blocked')->count());
    }

    public function test_blocking_requires_a_reason(): void
    {
        [$auction, $participant] = $this->auctionWithParticipant();

        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson($this->blockUrl($auction, $participant), ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_blocking_is_idempotent(): void
    {
        [$auction, $participant] = $this->auctionWithParticipant();
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')->postJson($this->blockUrl($auction, $participant), ['reason' => 'first'])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson($this->blockUrl($auction, $participant), ['reason' => 'second'])->assertOk();

        $this->assertSame('first', $participant->refresh()->block_reason);
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)
            ->where('event_type', 'auction.participant_blocked')->count());
    }

    public function test_blocked_participant_cannot_bid(): void
    {
        [$auction, $participant, $bidder] = $this->auctionWithParticipant(AuctionParticipantStatus::Qualified);

        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson($this->blockUrl($auction, $participant), ['reason' => 'fraud'])
            ->assertOk();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/bids', [
                'amount' => '60.000',
                'currency_code' => 'JOD',
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'bidder_not_qualified');
    }

    public function test_blocked_participant_state_is_exposed_to_the_user(): void
    {
        [$auction, $participant, $bidder] = $this->auctionWithParticipant(AuctionParticipantStatus::Qualified);

        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson($this->blockUrl($auction, $participant), ['reason' => 'fraud'])
            ->assertOk();

        $state = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data.my_participation');

        $this->assertSame('blocked', $state['participant_status']);
        $this->assertSame('blocked', $state['blocking_reason']);
        $this->assertFalse($state['can_bid']);
    }

    public function test_unblock_restores_the_previous_status(): void
    {
        [$auction, $participant] = $this->auctionWithParticipant(AuctionParticipantStatus::Qualified);
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')->postJson($this->blockUrl($auction, $participant), ['reason' => 'review'])->assertOk();

        $data = $this->actingAs($admin, 'sanctum')
            ->postJson($this->blockUrl($auction, $participant, 'unblock'), ['reason' => 'cleared'])
            ->assertOk()
            ->json('data');

        $this->assertSame('qualified', $data['status']);
        $this->assertNull($participant->refresh()->block_reason);
        $this->assertNull($participant->blocked_at);
    }

    public function test_winner_cannot_be_blocked_after_settlement_exists(): void
    {
        [$auction, $participant, $winner] = $this->auctionWithParticipant(AuctionParticipantStatus::Qualified);
        $auction->forceFill(['status' => AuctionStatus::HandoverPending])->save();

        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'block-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);

        AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $winner->id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::PaymentPending,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 0,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 100_000,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->addDay(),
        ]);

        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson($this->blockUrl($auction, $participant), ['reason' => 'too late'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'participant_block_winner_not_allowed');

        $this->assertSame(AuctionParticipantStatus::Qualified, $participant->refresh()->status);
    }

    public function test_non_admin_cannot_block(): void
    {
        [$auction, $participant] = $this->auctionWithParticipant();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson($this->blockUrl($auction, $participant), ['reason' => 'nope'])
            ->assertStatus(403);
    }

    public function test_admin_without_the_blocking_permission_is_denied(): void
    {
        [$auction, $participant] = $this->auctionWithParticipant();
        config()->set('auction.admin_permissions', array_values(array_diff(
            (array) config('auction.admin_permissions'),
            ['auction.participants.block']
        )));

        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson($this->blockUrl($auction, $participant), ['reason' => 'nope'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    private function blockUrl(Auction $auction, AuctionParticipant $participant, string $action = 'block'): string
    {
        return '/api/admin/auctions/'.$auction->public_id.'/participants/'.$participant->public_id.'/'.$action;
    }

    private function auctionWithParticipant(AuctionParticipantStatus $status = AuctionParticipantStatus::Registered): array
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
                'bidder_deposit_minor' => 0,
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
            'category_id' => Category::create(['name' => 'blk-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create(['name' => 'blk-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Blocking auction '.Str::ulid(),
            'description' => 'Blocking auction.',
            'status' => AuctionStatus::Live,
            'starting_amount_minor' => 50_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 0,
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

        $bidder = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'status' => $status,
            'registered_at' => now()->subDay(),
            'qualified_at' => $status === AuctionParticipantStatus::Qualified ? now()->subHour() : null,
        ]);

        return [$auction, $participant, $bidder];
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "blk-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
