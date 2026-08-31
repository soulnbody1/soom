<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\OutboxMessage;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Support\AuctionConfigurationSnapshotHasher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\AcceptsAuctionTerms;
use Tests\TestCase;

/**
 * Once-only participation actions must reject a repeat call with 409 instead of
 * silently returning 201, and must never duplicate their audit or outbox rows.
 */
final class DuplicateParticipationActionsTest extends TestCase
{
    use AcceptsAuctionTerms;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_registering_twice_is_idempotent_and_records_a_single_trail(): void
    {
        $auction = $this->liveAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
            ->assertCreated();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertSame(1, AuctionParticipant::where('auction_id', $auction->id)
            ->where('user_id', $bidder->id)
            ->count());
        $this->assertSame(1, AuctionTermsAcceptance::where('auction_id', $auction->id)
            ->where('user_id', $bidder->id)
            ->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)
            ->where('event_type', 'auction.participant_registered')
            ->count());
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.participant_registered')
            ->count());
    }

    public function test_accepting_terms_twice_is_rejected_and_records_a_single_trail(): void
    {
        $auction = $this->liveAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
            ->assertCreated();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'terms_already_accepted')
            ->assertJsonPath('message', __('auction.errors.terms_already_accepted'));

        $this->assertSame(1, AuctionTermsAcceptance::where('auction_id', $auction->id)
            ->where('user_id', $bidder->id)
            ->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)
            ->where('event_type', 'auction.terms_accepted')
            ->count());
    }

    public function test_a_newer_pinned_terms_version_can_still_be_accepted(): void
    {
        $auction = $this->liveAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
            ->assertCreated();

        $newer = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms v2',
            'body' => 'Revised auction terms.',
            'is_active' => true,
            'published_at' => now(),
        ]);

        // The snapshot is immutable through Eloquent, so re-pin it at the query level
        // and recompute its integrity hash the same way the writer does.
        DB::table('auction_configuration_snapshots')
            ->where('auction_id', $auction->id)
            ->update(['terms_version_id' => $newer->id]);

        $snapshot = AuctionConfigurationSnapshot::where('auction_id', $auction->id)->firstOrFail();

        DB::table('auction_configuration_snapshots')
            ->where('auction_id', $auction->id)
            ->update(['snapshot_hash' => app(AuctionConfigurationSnapshotHasher::class)->hash($snapshot->toArray())]);

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')
            ->assertCreated();

        $this->assertSame(2, AuctionTermsAcceptance::where('auction_id', $auction->id)
            ->where('user_id', $bidder->id)
            ->count());
    }

    public function test_blocked_participant_is_rejected_on_register_and_accept_terms(): void
    {
        $auction = $this->liveAuction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
            ->assertCreated();

        AuctionParticipant::where('auction_id', $auction->id)
            ->where('user_id', $bidder->id)
            ->firstOrFail()
            ->forceFill(['status' => AuctionParticipantStatus::Blocked, 'blocked_at' => now()])
            ->save();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
            ->assertStatus(403)
            ->assertJsonPath('code', 'blocked_participant');

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/accept-terms')
            ->assertStatus(403)
            ->assertJsonPath('code', 'blocked_participant');

        $this->assertSame(1, AuctionTermsAcceptance::where('auction_id', $auction->id)
            ->where('user_id', $bidder->id)
            ->count());
    }

    private function liveAuction(): Auction
    {
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
        $category = Category::create(['name' => 'dup-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'dup-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Duplicate action auction '.Str::ulid(),
            'description' => 'Duplicate action auction.',
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

        $this->snapshotApprovedAuction($auction);

        return $auction;
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "dup-action-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
