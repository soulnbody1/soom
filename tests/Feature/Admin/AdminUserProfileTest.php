<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Ad;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PayoutDestination;
use App\Models\Auction\RefundTransaction;
use App\Models\Auction\PaymentMethod;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Favorite;
use App\Models\Message;
use App\Models\State;
use App\Models\User;
use App\Models\UserAdInteraction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminUserProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);

        Storage::fake('spaces');
    }

    public function test_overview_returns_identity_counts_and_recent_activity(): void
    {
        $member = $this->user();
        $ad = $this->ad($member);
        Favorite::create(['user_id' => $member->id, 'ad_id' => $ad->id]);
        UserAdInteraction::create(['user_id' => $member->id, 'ad_id' => $ad->id, 'action' => 'save']);
        $auction = $this->auction($member);
        AuctionActivityLog::create([
            'auction_id' => $auction->id,
            'user_id' => $member->id,
            'event_type' => 'auction.created',
            'actor_type' => 'user',
            'created_at' => now(),
        ]);

        $payload = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/users/'.$member->id)
            ->assertOk()
            ->json('data');

        $this->assertSame($member->id, $payload['id']);
        $this->assertSame($member->phone, $payload['phone']);
        $this->assertFalse($payload['is_blocked']);
        $this->assertSame(1, $payload['counts']['ads']);
        $this->assertSame(1, $payload['counts']['favorites']);
        $this->assertSame(1, $payload['counts']['saved_ads']);
        $this->assertSame(1, $payload['counts']['auctions_created']);
        $this->assertSame('auction.created', $payload['recent_activity'][0]['event_type']);
        $this->assertArrayHasKey('last_login_at', $payload['session']);
    }

    public function test_overview_never_exposes_push_token(): void
    {
        $member = $this->user();
        $member->forceFill(['fcm_token' => 'secret-device-token'])->save();

        $payload = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/users/'.$member->id)
            ->assertOk()
            ->json('data');

        $this->assertTrue($payload['has_push_token']);
        $this->assertArrayNotHasKey('fcm_token', $payload);
    }

    public function test_blocked_user_profile_is_still_reachable(): void
    {
        $member = $this->user();
        $member->delete();

        $payload = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/users/'.$member->id)
            ->assertOk()
            ->json('data');

        $this->assertTrue($payload['is_blocked']);
        $this->assertNotNull($payload['blocked_at']);
    }

    public function test_non_admin_cannot_read_any_profile_tab(): void
    {
        $member = $this->user();
        $intruder = $this->user();

        foreach (['', '/ads', '/auctions', '/financial/summary', '/conversations', '/activity'] as $suffix) {
            $this->actingAs($intruder, 'sanctum')
                ->getJson('/api/admin/users/'.$member->id.$suffix)
                ->assertForbidden();
        }
    }

    public function test_ads_tab_paginates_and_filters_by_status(): void
    {
        $member = $this->user();
        $visible = $this->ad($member, 'Visible ad');
        $blocked = $this->ad($member, 'Blocked ad');
        $blocked->delete();

        $admin = $this->user('admin');

        $all = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$member->id.'/ads')
            ->assertOk();

        $this->assertSame(2, $all->json('total'));

        $active = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$member->id.'/ads?status=active')
            ->assertOk();

        $this->assertSame(1, $active->json('total'));
        $this->assertSame($visible->id, $active->json('data.0.id'));
        $this->assertFalse($active->json('data.0.is_blocked'));
    }

    public function test_favorites_and_saved_tabs_return_the_interacted_content(): void
    {
        $member = $this->user();
        $owner = $this->user();
        $ad = $this->ad($owner, 'Favorited ad');
        Favorite::create(['user_id' => $member->id, 'ad_id' => $ad->id]);
        UserAdInteraction::create(['user_id' => $member->id, 'ad_id' => $ad->id, 'action' => 'save']);
        UserAdInteraction::create(['user_id' => $member->id, 'ad_id' => $ad->id, 'action' => 'click']);

        $admin = $this->user('admin');

        $favorites = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$member->id.'/favorites')
            ->assertOk();

        $this->assertSame(1, $favorites->json('total'));
        $this->assertSame('Favorited ad', $favorites->json('data.0.title'));

        $saved = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$member->id.'/saved')
            ->assertOk();

        $this->assertSame(1, $saved->json('total'));
        $this->assertSame($ad->id, $saved->json('data.0.ad.id'));
    }

    public function test_auction_scopes_split_created_participated_and_won(): void
    {
        $seller = $this->user();
        $bidder = $this->user();
        $created = $this->auction($seller, 'Created auction');
        $joined = $this->auction($this->user(), 'Joined auction');

        $participant = AuctionParticipant::create([
            'auction_id' => $joined->id,
            'user_id' => $bidder->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subHour(),
            'qualified_at' => now()->subHour(),
        ]);

        $bid = AuctionBid::create([
            'auction_id' => $joined->id,
            'participant_id' => $participant->id,
            'bidder_id' => $bidder->id,
            'amount_minor' => 12_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => (string) Str::ulid(),
            'server_received_at' => now(),
            'accepted_at' => now(),
        ]);

        AuctionSettlement::create([
            'auction_id' => $joined->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bidder->id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::PaymentPending,
            'winning_amount_minor' => 12_000,
            'deposit_applied_minor' => 0,
            'platform_fee_minor' => 300,
            'seller_net_amount_minor' => 11_700,
            'amount_due_minor' => 12_000,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => 12_000,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->addDay(),
        ]);

        $admin = $this->user('admin');

        $createdList = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$seller->id.'/auctions?scope=created')
            ->assertOk();

        $this->assertSame(1, $createdList->json('total'));
        $this->assertSame($created->public_id, $createdList->json('data.0.id'));
        $this->assertSame('created', $createdList->json('scope'));

        $participated = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$bidder->id.'/auctions?scope=participated')
            ->assertOk();

        $this->assertSame(1, $participated->json('total'));
        $this->assertSame(1, $participated->json('data.0.user_bids_count'));
        $this->assertSame('qualified', $participated->json('data.0.participant_status'));
        $this->assertTrue($participated->json('data.0.is_winner'));

        $won = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$bidder->id.'/auctions?scope=won')
            ->assertOk();

        $this->assertSame(1, $won->json('total'));
        $this->assertSame($joined->public_id, $won->json('data.0.id'));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$bidder->id.'/auctions?scope=nowhere')
            ->assertUnprocessable();
    }

    public function test_financial_tabs_expose_amounts_without_storage_paths(): void
    {
        $member = $this->user();
        $auction = $this->auction($member);

        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $member->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => 1_000,
            'held_amount_minor' => 1_000,
            'applied_amount_minor' => 0,
            'refunded_amount_minor' => 0,
            'forfeited_amount_minor' => 0,
            'currency_code' => 'JOD',
            'submitted_at' => now()->subHour(),
            'held_at' => now()->subHour(),
        ]);

        RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'obligation_type' => 'bidder_deposit',
            'obligation_id' => $deposit->id,
            'user_id' => $member->id,
            'recipient_name' => 'Member Name',
            'identifier_type' => 'iban',
            'identifier_value' => 'JO00TEST',
            'status' => RefundTransactionStatus::Succeeded,
            'amount_minor' => 1_000,
            'currency_code' => 'JOD',
            'reason' => 'non_winner_release',
            'provider' => 'manual',
            'idempotency_key' => (string) Str::ulid(),
            'proof_disk' => 'spaces_private',
            'proof_path' => 'auction-refunds/secret.pdf',
            'proof_mime_type' => 'application/pdf',
            'proof_size_bytes' => 4096,
            'succeeded_at' => now(),
        ]);

        PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $member->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => 1_000,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'auction-receipts/secret.png',
            'receipt_mime_type' => 'image/png',
            'receipt_size_bytes' => 2048,
            'idempotency_key' => (string) Str::ulid(),
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now(),
        ]);

        $winner = $this->user();
        $winnerParticipant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $winner->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subHour(),
            'qualified_at' => now()->subHour(),
        ]);

        $winningBid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $winnerParticipant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 20_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => (string) Str::ulid(),
            'server_received_at' => now(),
            'accepted_at' => now(),
        ]);

        $settlement = AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $winningBid->id,
            'winner_id' => $winningBid->bidder_id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::Completed,
            'winning_amount_minor' => 20_000,
            'deposit_applied_minor' => 0,
            'platform_fee_minor' => 500,
            'seller_net_amount_minor' => 19_500,
            'amount_due_minor' => 20_000,
            'amount_paid_minor' => 20_000,
            'remaining_amount_minor' => 0,
            'currency_code' => 'JOD',
            'completed_at' => now(),
        ]);

        AuctionSellerPayout::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'seller_id' => $member->id,
            'status' => SellerPayoutStatus::Pending,
            'winning_amount_minor' => 20_000,
            'platform_fee_minor' => 500,
            'amount_minor' => 19_500,
            'currency_code' => 'JOD',
            'recipient_name' => 'Member Name',
            'identifier_type' => 'iban',
            'identifier_value' => 'JO00TEST',
        ]);

        PayoutDestination::create([
            'user_id' => $member->id,
            'recipient_name' => 'Member Name',
            'identifier_type' => 'iban',
            'identifier_value' => 'JO00TEST',
            'is_default' => true,
            'default_marker' => 1,
        ]);

        $admin = $this->user('admin');
        $base = '/api/admin/users/'.$member->id;

        $summary = $this->actingAs($admin, 'sanctum')->getJson($base.'/financial/summary')->assertOk()->json('data');
        $this->assertSame(1, $summary['refunds'][0]['count']);
        $this->assertSame('JOD', $summary['refunds'][0]['total']['currency']);

        $refunds = $this->actingAs($admin, 'sanctum')->getJson($base.'/financial/refunds')->assertOk();
        $this->assertSame(1, $refunds->json('total'));
        $this->assertTrue($refunds->json('data.0.has_proof'));
        $this->assertStringNotContainsString('secret.pdf', $refunds->getContent());

        $submissions = $this->actingAs($admin, 'sanctum')->getJson($base.'/financial/payment-submissions')->assertOk();
        $this->assertSame(1, $submissions->json('total'));
        $this->assertStringNotContainsString('secret.png', $submissions->getContent());

        $this->assertSame(1, $this->actingAs($admin, 'sanctum')->getJson($base.'/financial/deposits')->assertOk()->json('total'));
        $this->assertSame(1, $this->actingAs($admin, 'sanctum')->getJson($base.'/financial/payouts')->assertOk()->json('total'));

        $destinations = $this->actingAs($admin, 'sanctum')->getJson($base.'/payout-destinations')->assertOk();
        $this->assertTrue($destinations->json('data.0.is_default'));
        $this->assertSame('JO00TEST', $destinations->json('data.0.identifier_value'));
    }

    public function test_conversations_group_by_partner_and_expose_messages(): void
    {
        $member = $this->user();
        $partner = $this->user();
        $other = $this->user();

        Message::create(['sender_id' => $member->id, 'receiver_id' => $partner->id, 'content' => 'first', 'is_read' => true]);
        Message::create(['sender_id' => $partner->id, 'receiver_id' => $member->id, 'content' => 'second', 'is_read' => false]);
        Message::create(['sender_id' => $member->id, 'receiver_id' => $other->id, 'content' => 'third', 'is_read' => true]);

        $admin = $this->user('admin');

        $threads = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$member->id.'/conversations')
            ->assertOk();

        $this->assertSame(2, $threads->json('total'));
        $this->assertSame($other->id, $threads->json('data.0.partner.id'));

        $partnerThread = collect($threads->json('data'))->firstWhere('partner.id', $partner->id);
        $this->assertSame(2, $partnerThread['messages_count']);
        $this->assertSame(1, $partnerThread['unread_count']);
        $this->assertSame('second', $partnerThread['last_message']['preview']);

        $messages = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$member->id.'/conversations/'.$partner->id.'/messages')
            ->assertOk();

        $this->assertSame(2, $messages->json('total'));
        $this->assertSame('second', $messages->json('data.0.content'));
        $this->assertSame($partner->id, $messages->json('partner.id'));
    }

    public function test_activity_tab_filters_by_event_type_and_lists_available_types(): void
    {
        $member = $this->user();
        $auction = $this->auction($member);

        foreach (['auction.created', 'auction.bid_placed', 'auction.bid_placed'] as $event) {
            AuctionActivityLog::create([
                'auction_id' => $auction->id,
                'user_id' => $member->id,
                'event_type' => $event,
                'actor_type' => 'user',
                'created_at' => now(),
            ]);
        }

        $admin = $this->user('admin');

        $all = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$member->id.'/activity')
            ->assertOk();

        $this->assertSame(3, $all->json('total'));
        $this->assertSame(['auction.bid_placed', 'auction.created'], $all->json('event_types'));

        $filtered = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/'.$member->id.'/activity?event_type=auction.created')
            ->assertOk();

        $this->assertSame(1, $filtered->json('total'));
        $this->assertSame($auction->public_id, $filtered->json('data.0.auction.id'));
    }

    private function ad(User $owner, string $title = 'Ad title'): Ad
    {
        return Ad::create([
            'user_id' => $owner->id,
            'category_id' => $this->category()->id,
            'title' => $title,
            'description' => 'Ad description.',
            'price' => 100,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
        ]);
    }

    private function auction(User $seller, string $title = 'Auction title'): Auction
    {
        $terms = AuctionTermsVersion::firstOrCreate(
            ['version_number' => 1],
            ['title' => 'Terms', 'body' => 'Auction terms.', 'is_active' => true, 'published_at' => now()->subDay()]
        );

        return Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $this->category()->id,
            'country_id' => $this->country()->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $this->configuration()->id,
            'currency_code' => 'JOD',
            'title' => $title,
            'description' => 'Auction description.',
            'status' => AuctionStatus::Live,
            'starting_amount_minor' => 10_000,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 1_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->subHour(),
            'original_ends_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3),
        ]);
    }

    private function configuration(): AuctionConfigurationVersion
    {
        return AuctionConfigurationVersion::firstOrCreate(
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
    }

    private function state(): State
    {
        return State::firstOrCreate(['name' => 'profile-state'], ['country_id' => $this->country()->id]);
    }

    private function city(): City
    {
        return City::firstOrCreate(['name' => 'profile-city'], ['state_id' => $this->state()->id]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => 'profile-cliq'],
            [
                'name' => 'CliQ',
                'identifier_type' => 'cliq_alias',
                'identifier_value' => 'SOOM',
                'recipient_name' => 'Soom',
                'instructions' => 'Transfer to the alias.',
                'is_active' => true,
                'requires_manual_review' => true,
            ]
        );
    }

    private function category(): Category
    {
        return Category::firstOrCreate(['name' => 'profile-category'], ['display_order' => 0]);
    }

    private function country(): Country
    {
        return Country::firstOrCreate(['name' => 'profile-country'], ['code' => 'PRF']);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "profile-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
