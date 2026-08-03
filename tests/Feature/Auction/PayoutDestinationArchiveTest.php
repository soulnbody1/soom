<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PayoutDestination;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PayoutDestinationArchiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_owner_can_archive_a_destination_without_losing_history(): void
    {
        $user = $this->user();
        $destination = $this->destination($user, isDefault: false);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/soom/my/payout-destinations/'.$destination->public_id)
            ->assertOk();

        $this->assertSoftDeleted('payout_destinations', ['id' => $destination->id]);
        $this->assertNotNull(PayoutDestination::withTrashed()->find($destination->id));

        $rows = $this->actingAs($user, 'sanctum')
            ->getJson('/api/soom/my/payout-destinations')->assertOk()->json('data');
        $this->assertSame([], $rows);
    }

    public function test_destination_attached_to_an_in_flight_payout_cannot_be_archived(): void
    {
        $user = $this->user();
        $destination = $this->destination($user, isDefault: false);
        $this->payout($user, $destination, SellerPayoutStatus::Pending);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/soom/my/payout-destinations/'.$destination->public_id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'payout_destination_in_use');

        $this->assertNull($destination->fresh()->deleted_at);
    }

    public function test_default_destination_cannot_be_archived_without_a_replacement_when_payouts_wait(): void
    {
        $user = $this->user();
        $default = $this->destination($user, isDefault: true);
        $this->payout($user, null, SellerPayoutStatus::Pending);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/soom/my/payout-destinations/'.$default->public_id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'payout_destination_default_required');
    }

    public function test_archiving_the_default_promotes_a_replacement(): void
    {
        $user = $this->user();
        $default = $this->destination($user, isDefault: true);
        $other = $this->destination($user, isDefault: false);
        $this->payout($user, null, SellerPayoutStatus::Pending);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/soom/my/payout-destinations/'.$default->public_id)
            ->assertOk();

        $this->assertSoftDeleted('payout_destinations', ['id' => $default->id]);
        $this->assertTrue($other->fresh()->is_default);
    }

    public function test_paid_payouts_do_not_block_archiving(): void
    {
        $user = $this->user();
        $destination = $this->destination($user, isDefault: false);
        $this->payout($user, $destination, SellerPayoutStatus::Paid);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/soom/my/payout-destinations/'.$destination->public_id)
            ->assertOk();

        $this->assertSoftDeleted('payout_destinations', ['id' => $destination->id]);
    }

    public function test_another_user_cannot_archive_a_destination(): void
    {
        $owner = $this->user();
        $destination = $this->destination($owner, isDefault: false);

        $this->actingAs($this->user(), 'sanctum')
            ->deleteJson('/api/soom/my/payout-destinations/'.$destination->public_id)
            ->assertStatus(404)
            ->assertJsonPath('code', 'payout_destination_not_found');

        $this->assertNull($destination->fresh()->deleted_at);
    }

    private function destination(User $user, bool $isDefault): PayoutDestination
    {
        return PayoutDestination::create([
            'user_id' => $user->id,
            'recipient_name' => 'Recipient '.Str::ulid(),
            'identifier_type' => 'iban',
            'identifier_value' => 'JO94CBJO'.random_int(1000000, 9999999),
            'is_default' => $isDefault,
            'default_marker' => $isDefault ? 1 : null,
        ]);
    }

    private function payout(User $seller, ?PayoutDestination $destination, SellerPayoutStatus $status): AuctionSellerPayout
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
                'seller_deposit_minor' => 0,
                'bidder_deposit_minor' => 0,
                'minimum_bid_increment_minor' => 500,
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
                'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                'non_winner_deposit_hold_count' => 1,
            ],
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'dst-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create(['name' => 'dst-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Destination auction '.Str::ulid(),
            'description' => 'Destination auction.',
            'status' => AuctionStatus::Completed,
            'starting_amount_minor' => 50_000,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 0,
            'bidder_deposit_amount_minor' => 0,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->subDays(3),
            'original_ends_at' => now()->subDay(),
            'ends_at' => now()->subDay(),
        ]);

        $winner = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $winner->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subDays(2),
            'qualified_at' => now()->subDays(2),
        ]);
        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'dst-bid-'.Str::ulid(),
            'server_received_at' => now()->subDay(),
            'accepted_at' => now()->subDay(),
        ]);
        $settlement = AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $winner->id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::Completed,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 0,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 100_000,
            'amount_paid_minor' => 100_000,
            'remaining_amount_minor' => 0,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->subDay(),
            'paid_at' => now()->subDay(),
        ]);

        return AuctionSellerPayout::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'seller_id' => $seller->id,
            'status' => $status,
            'winning_amount_minor' => 100_000,
            'platform_fee_minor' => 2_500,
            'amount_minor' => 97_500,
            'currency_code' => 'JOD',
            'destination_id' => $destination?->id,
        ]);
    }

    private function user(): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "dest-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }
}
