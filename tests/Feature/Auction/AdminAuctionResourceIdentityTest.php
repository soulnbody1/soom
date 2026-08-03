<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
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
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAuctionResourceIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_admin_list_serializes_seller_identity(): void
    {
        [$auction, $seller] = $this->fullAuction();

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $auction->public_id);
        $this->assertNotNull($row);
        $this->assertSame($seller->id, $row['seller']['id']);
        $this->assertSame($seller->name, $row['seller']['name']);
        $this->assertSame($seller->phone, $row['seller']['phone']);
    }

    public function test_admin_detail_serializes_financial_identities_and_handover_timestamps(): void
    {
        [$auction, $seller, $bidder] = $this->fullAuction();

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id)
            ->assertOk();

        $data = $response->json('data');

        $this->assertSame($seller->id, $data['seller']['id']);

        $bidderDeposit = collect($data['deposits'])->firstWhere('type', 'bidder');
        $this->assertNotNull($bidderDeposit);
        $this->assertSame($bidder->id, $bidderDeposit['user']['id']);
        $this->assertSame($bidder->name, $bidderDeposit['user']['name']);

        $submission = collect($data['payment_submissions'])->first();
        $this->assertNotNull($submission);
        $this->assertSame($bidder->id, $submission['user']['id']);

        $settlement = $data['financial_details']['settlement'];
        $this->assertSame($bidder->id, $settlement['winner']['id']);
        $this->assertSame($bidder->name, $settlement['winner']['name']);
        $this->assertNotNull($settlement['paid_at']);
        $this->assertNotNull($settlement['seller_handover_confirmed_at']);
        $this->assertArrayHasKey('buyer_receipt_confirmed_at', $settlement);
        $this->assertArrayHasKey('handover_completed_at', $settlement);
        $this->assertArrayHasKey('completed_at', $settlement);
        $this->assertArrayHasKey('defaulted_at', $settlement);
    }

    public function test_owner_view_does_not_leak_admin_identity_fields(): void
    {
        [$auction, $seller, $bidder] = $this->fullAuction();

        $response = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk();

        $data = $response->json('data');

        $this->assertArrayHasKey('my_deposits', $data);
        foreach ($data['my_deposits'] as $deposit) {
            $this->assertArrayNotHasKey('user', $deposit);
        }
        foreach ($data['my_payment_submissions'] as $submission) {
            $this->assertArrayNotHasKey('user', $submission);
        }

        $this->assertArrayNotHasKey('financial_details', $data);
        $this->assertArrayNotHasKey('internal_id', $data);
        $this->assertArrayNotHasKey('deposits', $data);
        $this->assertArrayNotHasKey('payment_submissions', $data);

        $this->assertArrayHasKey('seller', $data);
        $this->assertArrayNotHasKey('id', $data['seller']);
        $this->assertArrayNotHasKey('phone', $data['seller']);
        $this->assertFalse($data['seller']['is_me']);

        $this->assertNull($data['seller_context']);
    }

    public function test_public_view_unchanged_for_guests(): void
    {
        [$auction] = $this->fullAuction();

        $data = $this->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('deposits', $data);
        $this->assertArrayNotHasKey('payment_submissions', $data);
        $this->assertArrayNotHasKey('financial_details', $data);
        $this->assertArrayNotHasKey('internal_id', $data);

        $this->assertSame([], $data['my_deposits']);
        $this->assertSame([], $data['my_payment_submissions']);
        $this->assertNull($data['winner_settlement']);
        $this->assertNull($data['seller_context']);
        $this->assertNull($data['current_dispute']);
        $this->assertSame('authentication_required', $data['my_participation']['blocking_reason']);
    }

    // ─── Fixtures ──────────────────────────────────────────────

    /**
     * Auction in handover_pending with: seller deposit, qualified bidder with held deposit,
     * approved winner-payment submission, and a paid settlement for the bidder.
     */
    private function fullAuction(): array
    {
        $seller = $this->user();
        $bidder = $this->user();
        $category = Category::create(['name' => 'identity-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'identity-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);
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

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Identity auction '.Str::ulid(),
            'description' => 'Identity auction.',
            'status' => AuctionStatus::HandoverPending,
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
            'starts_at' => now()->subDays(3),
            'original_ends_at' => now()->subDay(),
            'ends_at' => now()->subDay(),
        ]);

        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'status' => \App\Domain\Auction\Enums\AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subDays(2),
            'qualified_at' => now()->subDays(2),
        ]);

        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $bidder->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'identity-bid-'.Str::ulid(),
            'accepted_at' => now()->subDay(),
            'server_received_at' => now()->subDay(),
        ]);
        $auction->forceFill(['winning_bid_id' => $bid->id, 'current_leading_bid_id' => $bid->id])->save();

        AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $seller->id,
            'type' => 'seller',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => 2_000,
            'held_amount_minor' => 2_000,
            'currency_code' => 'JOD',
        ]);

        $bidderDeposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $bidder->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::AppliedToSettlement,
            'required_amount_minor' => 1_000,
            'held_amount_minor' => 1_000,
            'applied_amount_minor' => 1_000,
            'currency_code' => 'JOD',
        ]);

        $method = PaymentMethod::create([
            'name' => 'Manual transfer',
            'code' => 'identity-'.Str::ulid(),
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);

        PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $bidderDeposit->id,
            'user_id' => $bidder->id,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => 1_000,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'receipt.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'identity-sub-'.Str::ulid(),
            'submitted_at' => now()->subDays(2),
            'reviewed_at' => now()->subDay(),
        ]);

        AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bidder->id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::HandoverPending,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 1_000,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 99_000,
            'amount_paid_minor' => 99_000,
            'remaining_amount_minor' => 0,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->subHours(6),
            'handover_due_at' => now()->addDay(),
            'paid_at' => now()->subHours(5),
            'seller_handover_confirmed_at' => now()->subHour(),
        ]);

        return [$auction, $seller, $bidder];
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "identity-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
