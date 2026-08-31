<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Query budgets for the auction read hot paths.
 *
 * The absolute ceilings matter less than the invariant every assertion here
 * protects: the query count of a list endpoint must not grow with the number of
 * rows on the page. Raise a ceiling only alongside a deliberate change.
 */
final class AuctionQueryBudgetTest extends TestCase
{
    private const SMALL_PAGE = 3;

    private const LARGE_PAGE = 12;

    private ?int $categoryId = null;

    private ?int $countryId = null;

    private ?int $termsVersionId = null;

    private ?int $configurationVersionId = null;

    private ?int $paymentMethodId = null;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_seller_deposits_are_owned_by_the_seller(): void
    {
        $seller = $this->user();
        $auction = $this->auction($seller);
        $deposit = AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->sole();

        // UserAuctionResource derives seller_context from the viewer-scoped `deposits`
        // relation, which only works because the seller deposit belongs to the seller.
        $this->assertSame((int) $auction->seller_id, (int) $deposit->user_id);
    }

    public function test_my_deposits_report_pending_refunds_from_the_eager_loaded_refunds(): void
    {
        $viewer = $this->user();
        $auction = $this->auction($this->user());
        $this->attachViewerContext($auction, $viewer);

        $deposit = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data.my_deposits.0');

        // Without deposits.refunds eager-loaded these silently read as 0 / full held.
        $this->assertSame(2_500, $deposit['pending_refund_amount']['minor']);
        $this->assertSame(10_000, $deposit['held_amount']['minor']);
    }

    public function test_public_auction_list_query_count_does_not_grow_with_the_page_size(): void
    {
        $viewer = $this->user();

        $small = $this->measureList($viewer, self::SMALL_PAGE, '/api/auctions');
        $large = $this->measureList($viewer, self::LARGE_PAGE, '/api/auctions');

        $this->reportBudget('GET /api/auctions', $small, $large);

        $this->assertSame($small, $large, 'The public auction list must issue a constant number of queries.');
        $this->assertLessThanOrEqual(22, $large);
    }

    public function test_guest_auction_list_query_count_does_not_grow_with_the_page_size(): void
    {
        $small = $this->measureList(null, self::SMALL_PAGE, '/api/auctions');
        $large = $this->measureList(null, self::LARGE_PAGE, '/api/auctions');

        $this->reportBudget('GET /api/auctions (guest)', $small, $large);

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(14, $large);
    }

    public function test_seller_auction_list_query_count_does_not_grow_with_the_page_size(): void
    {
        $seller = $this->user();

        $small = $this->measureList($seller, self::SMALL_PAGE, '/api/soom/my/auctions', $seller);
        $large = $this->measureList($seller, self::LARGE_PAGE, '/api/soom/my/auctions', $seller);

        $this->reportBudget('GET /api/soom/my/auctions', $small, $large);

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(22, $large);
    }

    public function test_participation_list_query_count_does_not_grow_with_the_page_size(): void
    {
        $viewer = $this->user();

        $small = $this->measureList($viewer, self::SMALL_PAGE, '/api/soom/my/participations');
        $large = $this->measureList($viewer, self::LARGE_PAGE, '/api/soom/my/participations');

        $this->reportBudget('GET /api/soom/my/participations', $small, $large);

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(24, $large);
    }

    public function test_admin_auction_list_query_count_does_not_grow_with_the_page_size(): void
    {
        $admin = $this->user('admin');

        $small = $this->measureList($admin, self::SMALL_PAGE, '/api/admin/auctions');
        $large = $this->measureList($admin, self::LARGE_PAGE, '/api/admin/auctions');

        $this->reportBudget('GET /api/admin/auctions', $small, $large);

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(20, $large);
    }

    public function test_admin_refund_list_query_count_does_not_grow_with_the_page_size(): void
    {
        $admin = $this->user('admin');

        $small = $this->measureRefundList($admin, self::SMALL_PAGE);
        $large = $this->measureRefundList($admin, self::LARGE_PAGE);

        $this->reportBudget('GET /api/admin/auctions/refunds', $small, $large);

        $this->assertSame($small, $large, 'The admin refund list must issue a constant number of queries.');
        $this->assertLessThanOrEqual(12, $large);
    }

    public function test_auction_detail_query_budget(): void
    {
        $viewer = $this->user();
        $seller = $this->user();
        $auction = $this->auction($seller);
        $this->attachViewerContext($auction, $viewer);

        $count = $this->countQueries(function () use ($viewer, $auction): void {
            $this->actingAs($viewer, 'sanctum')
                ->getJson('/api/auctions/'.$auction->public_id)
                ->assertOk();
        });

        $this->reportSingle('GET /api/auctions/{auction}', $count);

        $this->assertLessThanOrEqual(30, $count);
    }

    public function test_admin_auction_detail_query_budget(): void
    {
        $admin = $this->user('admin');
        $seller = $this->user();
        $auction = $this->auction($seller);
        $this->attachViewerContext($auction, $this->user());

        $count = $this->countQueries(function () use ($admin, $auction): void {
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/admin/auctions/'.$auction->public_id)
                ->assertOk();
        });

        $this->reportSingle('GET /api/admin/auctions/{auction}', $count);

        $this->assertLessThanOrEqual(30, $count);
    }

    private function measureList(?User $viewer, int $auctionCount, string $path, ?User $seller = null): int
    {
        $this->resetAuctions();

        $seller ??= $this->user();
        $viewerIsSeller = $viewer !== null && (int) $viewer->id === (int) $seller->id;

        for ($index = 0; $index < $auctionCount; $index++) {
            $auction = $this->auction($seller);

            if ($viewer !== null && ! $viewerIsSeller) {
                $this->attachViewerContext($auction, $viewer);
            }
        }

        $url = $path.'?per_page='.($auctionCount + 5);

        $call = function () use ($viewer, $url): void {
            $request = $viewer === null ? $this : $this->actingAs($viewer, 'sanctum');
            $request->getJson($url)->assertOk();
        };

        // Warm the per-process singletons first so the measured run reflects a
        // steady-state request rather than the extra lookups of a cold one.
        $call();

        return $this->countQueries($call);
    }

    private function measureRefundList(User $admin, int $refundCount): int
    {
        $this->resetAuctions();

        $seller = $this->user();

        for ($index = 0; $index < $refundCount; $index++) {
            $auction = $this->auction($seller);
            $owner = $this->user();
            $deposit = $this->deposit($auction, $owner, 'bidder');
            $this->refund($auction, $owner, $deposit);
        }

        $call = function () use ($admin, $refundCount): void {
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/admin/auctions/refunds?per_page='.($refundCount + 5))
                ->assertOk();
        };

        $call();

        return $this->countQueries($call);
    }

    private function attachViewerContext(Auction $auction, User $viewer): void
    {
        $participant = $this->participant($auction, $viewer);
        $bid = $this->bid($auction, $participant, $viewer);
        $deposit = $this->deposit($auction, $viewer, 'bidder');
        $this->refund($auction, $viewer, $deposit);
        $this->settlement($auction, $bid);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    private function reportBudget(string $endpoint, int $small, int $large): void
    {
        fwrite(STDERR, sprintf(
            "\n[query-budget] %s : %d rows => %d queries | %d rows => %d queries\n",
            $endpoint,
            self::SMALL_PAGE,
            $small,
            self::LARGE_PAGE,
            $large
        ));
    }

    private function reportSingle(string $endpoint, int $count): void
    {
        fwrite(STDERR, sprintf("\n[query-budget] %s : %d queries\n", $endpoint, $count));
    }

    private function resetAuctions(): void
    {
        Auction::query()->update(['current_leading_bid_id' => null, 'winning_bid_id' => null]);
        RefundTransaction::query()->delete();
        AuctionSettlement::query()->delete();
        PaymentSubmission::query()->delete();
        AuctionDeposit::query()->delete();
        AuctionBid::query()->delete();
        AuctionParticipant::query()->delete();
        DB::table('auction_configuration_snapshots')->delete();
        DB::table('auction_metrics')->delete();
        DB::table('auction_views')->delete();
        Auction::query()->delete();
    }

    private function auction(User $seller): Auction
    {
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $this->categoryId(),
            'country_id' => $this->countryId(),
            'terms_version_id' => $this->termsVersionId(),
            'configuration_version_id' => $this->configurationVersionId(),
            'currency_code' => 'JOD',
            'title' => 'Budget auction '.Str::ulid(),
            'description' => 'Budget auction.',
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
            'started_at' => now()->subDay(),
            'published_at' => now()->subDays(2),
            'original_ends_at' => now()->addDay(),
            'ends_at' => now()->addDay(),
        ]);

        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction->refresh(), $seller->id);

        // Every real auction carries a seller deposit with a payment submission, so
        // the fixture has to as well — otherwise the seller-side eager loads
        // short-circuit on an empty parent set and the measurement flatters itself.
        $sellerDeposit = $this->deposit($auction, $seller, 'seller');
        $this->paymentSubmission($auction, $seller, $sellerDeposit);

        return $auction;
    }

    private function paymentSubmission(Auction $auction, User $user, AuctionDeposit $deposit): PaymentSubmission
    {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $user->id,
            'payment_method_id' => $this->paymentMethodId(),
            'purpose' => $deposit->type === 'seller' ? 'seller_deposit' : 'bidder_deposit',
            'status' => 'approved',
            'amount_minor' => 10_000,
            'currency_code' => 'JOD',
            'receipt_disk' => 'local',
            'receipt_path' => 'receipts/budget-'.Str::ulid().'.jpg',
            'receipt_mime_type' => 'image/jpeg',
            'receipt_size_bytes' => 1024,
            'idempotency_key' => 'budget-submission-'.Str::ulid(),
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now()->subHours(12),
        ]);
    }

    private function paymentMethodId(): int
    {
        return $this->paymentMethodId ??= PaymentMethod::create([
            'name' => 'Budget bank transfer',
            'code' => 'budget_bank_'.Str::ulid(),
            'instructions' => 'Upload a receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ])->id;
    }

    private function participant(Auction $auction, User $user): AuctionParticipant
    {
        return AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subDay(),
            'qualified_at' => now()->subHour(),
        ]);
    }

    private function bid(Auction $auction, AuctionParticipant $participant, User $user): AuctionBid
    {
        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'budget-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);

        $auction->forceFill(['current_leading_bid_id' => $bid->id, 'winning_bid_id' => $bid->id])->save();

        return $bid;
    }

    private function deposit(Auction $auction, User $user, string $type): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'type' => $type,
            'status' => 'held',
            'required_amount_minor' => 10_000,
            'held_amount_minor' => 10_000,
            'currency_code' => 'JOD',
            'idempotency_key' => 'budget-deposit-'.Str::ulid(),
            'submitted_at' => now()->subDay(),
            'held_at' => now()->subDay(),
        ]);
    }

    private function refund(Auction $auction, User $user, AuctionDeposit $deposit): RefundTransaction
    {
        return RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'amount_minor' => 2_500,
            'currency_code' => 'JOD',
            'reason' => 'budget-test',
            'provider' => 'manual',
            'idempotency_key' => 'budget-refund-'.Str::ulid(),
        ]);
    }

    private function settlement(Auction $auction, AuctionBid $bid): AuctionSettlement
    {
        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
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
            'handover_due_at' => now()->addDays(3),
        ]);
    }

    private function categoryId(): int
    {
        return $this->categoryId ??= Category::create([
            'name' => 'budget-cat-'.Str::ulid(),
            'display_order' => 0,
        ])->id;
    }

    private function countryId(): int
    {
        return $this->countryId ??= Country::create([
            'name' => 'budget-country-'.Str::ulid(),
            'code' => strtoupper(substr((string) Str::ulid(), 0, 6)),
        ])->id;
    }

    private function termsVersionId(): int
    {
        return $this->termsVersionId ??= AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ])->id;
    }

    private function configurationVersionId(): int
    {
        return $this->configurationVersionId ??= AuctionConfigurationVersion::create([
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
        ])->id;
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "budget-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
