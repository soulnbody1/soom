<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\BidBlockingReason;
use App\Domain\Auction\Enums\NextActionCode;
use App\Domain\Auction\Enums\ParticipationDepositStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use App\Repositories\Auction\Queries\ViewerAuctionQuery;
use App\Services\Auction\Support\ParticipationStateResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ParticipationStateResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_guest_gets_authentication_required_and_login_action(): void
    {
        $auction = $this->auction();

        $state = app(ParticipationStateResolver::class)->forAuction($auction, null);

        $this->assertFalse($state->isRegistered);
        $this->assertSame(BidBlockingReason::AuthenticationRequired, $state->blockingReason);
        $this->assertSame(NextActionCode::Login, $state->nextAction);
        $this->assertTrue($state->nextActionAllowed);
        $this->assertFalse($state->canBid);
        $this->assertSame(10_000, $state->depositRequiredMinor);
    }

    public function test_authenticated_stranger_is_not_registered(): void
    {
        $auction = $this->auction();

        $state = app(ParticipationStateResolver::class)->forAuction($auction, $this->user());

        $this->assertFalse($state->isRegistered);
        $this->assertSame(BidBlockingReason::NotRegistered, $state->blockingReason);
        $this->assertSame(NextActionCode::Register, $state->nextAction);
        $this->assertSame(ParticipationDepositStatus::NotSubmitted, $state->depositStatus);
    }

    public function test_registered_without_terms_is_asked_for_terms(): void
    {
        $auction = $this->auction();
        $user = $this->user();
        $this->participant($auction, $user, AuctionParticipantStatus::Registered);

        $state = app(ParticipationStateResolver::class)->forAuction($auction, $user);

        $this->assertTrue($state->isRegistered);
        $this->assertFalse($state->termsAccepted);
        $this->assertSame(BidBlockingReason::TermsRequired, $state->blockingReason);
        $this->assertSame(NextActionCode::AcceptTerms, $state->nextAction);
    }

    public function test_registered_with_terms_but_no_deposit_is_asked_for_deposit(): void
    {
        $auction = $this->auction();
        $user = $this->user();
        $participant = $this->participant($auction, $user, AuctionParticipantStatus::Registered);
        $this->acceptTerms($auction, $participant, $user);

        $state = app(ParticipationStateResolver::class)->forAuction($auction, $user);

        $this->assertTrue($state->termsAccepted);
        $this->assertNotNull($state->termsAcceptedAt);
        $this->assertSame(BidBlockingReason::DepositRequired, $state->blockingReason);
        $this->assertSame(NextActionCode::SubmitBidderDeposit, $state->nextAction);
    }

    public function test_deposit_under_review_is_reported_distinctly(): void
    {
        $auction = $this->auction();
        $user = $this->user();
        $participant = $this->participant($auction, $user, AuctionParticipantStatus::Registered);
        $this->acceptTerms($auction, $participant, $user);
        $this->deposit($auction, $participant, $user, AuctionDepositStatus::PendingReview);

        $state = app(ParticipationStateResolver::class)->forAuction($auction, $user);

        $this->assertSame(BidBlockingReason::DepositUnderReview, $state->blockingReason);
        $this->assertSame(ParticipationDepositStatus::PendingReview, $state->depositStatus);
        $this->assertSame(NextActionCode::AwaitDepositReview, $state->nextAction);
        $this->assertFalse($state->nextActionAllowed);
    }

    public function test_qualified_bidder_can_bid_and_is_flagged_as_highest(): void
    {
        $auction = $this->auction();
        $user = $this->user();
        $participant = $this->participant($auction, $user, AuctionParticipantStatus::Qualified);
        $this->acceptTerms($auction, $participant, $user);
        $this->deposit($auction, $participant, $user, AuctionDepositStatus::Held);
        $bid = $this->bid($auction, $participant, $user, 55_000);
        $auction->forceFill(['current_leading_bid_id' => $bid->id])->save();

        $auction = app(ViewerAuctionQuery::class)->loadDetails($auction->refresh(), $user->id);
        $state = app(ParticipationStateResolver::class)->forAuction($auction, $user);

        $this->assertTrue($state->canBid);
        $this->assertSame(BidBlockingReason::None, $state->blockingReason);
        $this->assertSame(NextActionCode::PlaceBid, $state->nextAction);
        $this->assertTrue($state->isHighestBidder);
        $this->assertSame(55_000, $state->myHighestBidMinor);
        $this->assertSame(1, $state->myBidsCount);
    }

    public function test_blocked_participant_is_reported_and_routed_to_support(): void
    {
        $auction = $this->auction();
        $user = $this->user();
        $participant = $this->participant($auction, $user, AuctionParticipantStatus::Blocked);
        $this->acceptTerms($auction, $participant, $user);

        $state = app(ParticipationStateResolver::class)->forAuction($auction, $user);

        $this->assertSame(BidBlockingReason::ParticipantBlocked, $state->blockingReason);
        $this->assertSame(NextActionCode::ContactSupport, $state->nextAction);
        $this->assertFalse($state->canBid);
    }

    public function test_seller_sees_is_seller_and_cannot_bid(): void
    {
        $auction = $this->auction();
        $seller = User::findOrFail($auction->seller_id);

        $state = app(ParticipationStateResolver::class)->forAuction($auction, $seller);

        $this->assertTrue($state->isSeller);
        $this->assertSame(BidBlockingReason::IsSeller, $state->blockingReason);
        $this->assertFalse($state->canBid);
    }

    public function test_missing_configuration_snapshot_degrades_instead_of_failing(): void
    {
        $auction = $this->auction(withSnapshot: false);
        $user = $this->user();

        $state = app(ParticipationStateResolver::class)->forAuction($auction, $user);

        $this->assertSame(BidBlockingReason::ConfigurationUnavailable, $state->blockingReason);
        $this->assertSame(NextActionCode::ContactSupport, $state->nextAction);
        $this->assertSame(10_000, $state->depositRequiredMinor);
    }

    public function test_resolving_a_page_of_auctions_uses_a_constant_number_of_queries(): void
    {
        $user = $this->user();
        $shared = $this->auction();

        for ($i = 0; $i < 19; $i++) {
            $this->auction(
                termsVersionId: (int) $shared->terms_version_id,
                configurationVersionId: (int) $shared->configuration_version_id
            );
        }

        foreach (Auction::all() as $auction) {
            $participant = $this->participant($auction, $user, AuctionParticipantStatus::Qualified);
            $this->acceptTerms($auction, $participant, $user);
            $this->deposit($auction, $participant, $user, AuctionDepositStatus::Held);
        }

        $page = app(ViewerAuctionQuery::class)->paginate([], $user->id, 20);
        $this->assertCount(20, $page->items());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        app(ParticipationStateResolver::class)->forCollection($page->items(), $user);

        $this->assertLessThanOrEqual(12, $queries, "Resolver issued {$queries} queries for a 20-auction page");
    }

    private function auction(bool $withSnapshot = true, ?int $termsVersionId = null, ?int $configurationVersionId = null): Auction
    {
        $seller = $this->user();
        $terms = $termsVersionId !== null
            ? AuctionTermsVersion::findOrFail($termsVersionId)
            : AuctionTermsVersion::create([
                'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
                'title' => 'Terms',
                'body' => 'Auction terms.',
                'is_active' => true,
                'published_at' => now()->subDay(),
            ]);
        $configuration = $configurationVersionId !== null
            ? AuctionConfigurationVersion::findOrFail($configurationVersionId)
            : AuctionConfigurationVersion::create([
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
        $category = Category::create(['name' => 'participation-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'participation-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Participation auction '.Str::ulid(),
            'description' => 'Participation auction.',
            'status' => AuctionStatus::Live,
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
            'starts_at' => now()->subDay(),
            'original_ends_at' => now()->addDay(),
            'ends_at' => now()->addDay(),
        ]);

        if ($withSnapshot) {
            app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction, $seller->id);
        }

        return $auction;
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
            'held_amount_minor' => $status === AuctionDepositStatus::Held ? 10_000 : 0,
            'currency_code' => 'JOD',
            'held_at' => $status === AuctionDepositStatus::Held ? now()->subHour() : null,
        ]);
    }

    private function bid(Auction $auction, AuctionParticipant $participant, User $user, int $amountMinor): AuctionBid
    {
        return AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => $amountMinor,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'participation-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "participation-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
