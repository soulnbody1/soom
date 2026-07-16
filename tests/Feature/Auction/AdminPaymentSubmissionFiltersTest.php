<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminPaymentSubmissionFiltersTest extends TestCase
{
    private ?Category $category = null;

    private ?Country $country = null;

    private ?PaymentMethod $method = null;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_status_filter_narrows_submissions(): void
    {
        $auction = $this->makeAuction();
        $this->makeSubmission($auction, PaymentPurpose::SellerDeposit, PaymentSubmissionStatus::PendingReview);
        $this->makeSubmission($auction, PaymentPurpose::BidderDeposit, PaymentSubmissionStatus::Approved);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/payment-submissions?status=pending_review')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('pending_review', $response->json('data.0.status'));
    }

    public function test_purpose_filter_narrows_submissions(): void
    {
        $auction = $this->makeAuction();
        $this->makeSubmission($auction, PaymentPurpose::SellerDeposit, PaymentSubmissionStatus::PendingReview);
        $this->makeSubmission($auction, PaymentPurpose::BidderDeposit, PaymentSubmissionStatus::PendingReview);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/payment-submissions?purpose=bidder_deposit')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('bidder_deposit', $response->json('data.0.purpose'));
    }

    public function test_auction_id_filter_accepts_numeric_id_and_public_ulid(): void
    {
        $target = $this->makeAuction();
        $other = $this->makeAuction();
        $this->makeSubmission($target, PaymentPurpose::SellerDeposit, PaymentSubmissionStatus::PendingReview);
        $this->makeSubmission($other, PaymentPurpose::SellerDeposit, PaymentSubmissionStatus::PendingReview);

        $byNumeric = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/payment-submissions?auction_id='.$target->id)
            ->assertOk();
        $this->assertSame(1, $byNumeric->json('total'));
        $this->assertSame($target->public_id, $byNumeric->json('data.0.auction_id'));

        $byUlid = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/payment-submissions?auction_id='.$target->public_id)
            ->assertOk();
        $this->assertSame(1, $byUlid->json('total'));
        $this->assertSame($target->public_id, $byUlid->json('data.0.auction_id'));
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/payment-submissions?status=paid')
            ->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/payment-submissions?purpose=donation')
            ->assertUnprocessable();
    }

    public function test_non_admin_cannot_access_submissions_queue(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/admin/auctions/payment-submissions')
            ->assertForbidden();
    }

    // ─── Fixtures ──────────────────────────────────────────────

    private function makeSubmission(
        Auction $auction,
        PaymentPurpose $purpose,
        PaymentSubmissionStatus $status
    ): PaymentSubmission {
        $owner = $this->user();
        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $owner->id,
            'type' => $purpose === PaymentPurpose::SellerDeposit ? 'seller' : 'bidder',
            'status' => AuctionDepositStatus::PendingReview,
            'required_amount_minor' => 1_000,
            'currency_code' => $auction->currency_code,
        ]);

        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $owner->id,
            'payment_method_id' => $this->method()->id,
            'purpose' => $purpose,
            'status' => $status,
            'amount_minor' => 1_000,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'receipt.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'admin-sub-filter-'.Str::ulid(),
            'submitted_at' => now(),
        ]);
    }

    private function makeAuction(): Auction
    {
        return Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => $this->category()->id,
            'country_id' => $this->country()->id,
            'terms_version_id' => $this->terms()->id,
            'configuration_version_id' => $this->configuration()->id,
            'currency_code' => 'JOD',
            'title' => 'Submission filter auction '.Str::ulid(),
            'description' => 'Submission filter auction.',
            'status' => AuctionStatus::AwaitingSellerDeposit,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 1_000,
            'bidder_deposit_amount_minor' => 1_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->addDay(),
            'original_ends_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3),
        ]);
    }

    private function category(): Category
    {
        return $this->category ??= Category::create(['name' => 'sub-filter-cat-'.Str::ulid(), 'display_order' => 0]);
    }

    private function country(): Country
    {
        return $this->country ??= Country::create([
            'name' => 'sub-filter-country-'.Str::ulid(),
            'code' => strtoupper(substr((string) Str::ulid(), 0, 6)),
        ]);
    }

    private function method(): PaymentMethod
    {
        return $this->method ??= PaymentMethod::create([
            'name' => 'Manual transfer',
            'code' => 'sub-filter-'.Str::ulid(),
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
    }

    private function terms(): AuctionTermsVersion
    {
        return AuctionTermsVersion::firstOrCreate(
            ['version_number' => 1],
            ['title' => 'Terms', 'body' => 'Auction terms.', 'is_active' => true, 'published_at' => now()->subDay()]
        );
    }

    private function configuration(): AuctionConfigurationVersion
    {
        return AuctionConfigurationVersion::firstOrCreate(
            ['version_number' => 1],
            [
                'configuration' => [
                    'seller_deposit_minor' => 1_000,
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
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "sub-filter-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
