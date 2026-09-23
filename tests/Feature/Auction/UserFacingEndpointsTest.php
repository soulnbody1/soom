<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\AcceptsAuctionTerms;
use Tests\TestCase;

final class UserFacingEndpointsTest extends TestCase
{
    use AcceptsAuctionTerms;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_guest_can_read_auction_terms_body_without_acceptance_data(): void
    {
        $auction = $this->auction();

        $data = $this->getJson('/api/auctions/'.$auction->public_id.'/terms')->assertOk()->json('data');

        $this->assertSame('Auction terms.', $data['body']);
        $this->assertNotNull($data['version_number']);
        $this->assertFalse($data['accepted']);
        $this->assertNull($data['accepted_at']);
    }

    public function test_terms_endpoint_returns_the_snapshot_version_not_the_active_one(): void
    {
        $auction = $this->auction();
        $originalVersion = $this->getJson('/api/auctions/'.$auction->public_id.'/terms')->json('data.version_number');

        AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Newer terms',
            'body' => 'Newer body.',
            'is_active' => true,
            'published_at' => now(),
        ]);

        $data = $this->getJson('/api/auctions/'.$auction->public_id.'/terms')->assertOk()->json('data');

        $this->assertSame($originalVersion, $data['version_number']);
        $this->assertSame('Auction terms.', $data['body']);
    }

    public function test_terms_endpoint_reports_acceptance_for_the_authenticated_user(): void
    {
        $auction = $this->auction();
        $bidder = $this->user();

        $this->actingAs($bidder, 'sanctum')->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))->assertCreated();

        $data = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/auctions/'.$auction->public_id.'/terms')
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['accepted']);
        $this->assertNotNull($data['accepted_at']);
    }

    public function test_participations_list_only_returns_auctions_the_user_joined(): void
    {
        $joined = $this->auction();
        $this->auction();
        $bidder = $this->user();
        $this->participant($joined, $bidder, AuctionParticipantStatus::Registered);

        $rows = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/my/participations')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($joined->public_id, $rows[0]['id']);
        $this->assertTrue($rows[0]['my_participation']['is_registered']);
        $this->assertArrayHasKey('next_action', $rows[0]);
    }

    public function test_participations_filters_are_applied_in_the_database(): void
    {
        $bidder = $this->user();

        $active = $this->auction();
        $this->participant($active, $bidder, AuctionParticipantStatus::Qualified);

        $won = $this->auction(AuctionStatus::HandoverPending);
        $participant = $this->participant($won, $bidder, AuctionParticipantStatus::Qualified);
        $bid = $this->bid($won, $participant, $bidder);
        $this->settlement($won, $bid, SettlementStatus::PaymentPending);

        $activeRows = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/my/participations?filter=active')->assertOk()->json('data');
        $this->assertSame([$active->public_id], array_column($activeRows, 'id'));

        $wonRows = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/my/participations?filter=won')->assertOk()->json('data');
        $this->assertSame([$won->public_id], array_column($wonRows, 'id'));

        $pendingRows = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/my/participations?filter=pending_payment')->assertOk()->json('data');
        $this->assertSame([$won->public_id], array_column($pendingRows, 'id'));

        $lostRows = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/my/participations?filter=lost')->assertOk()->json('data');
        $this->assertSame([], array_column($lostRows, 'id'));
    }

    public function test_participations_search_matches_title_and_public_id(): void
    {
        $bidder = $this->user();
        $auction = $this->auction();
        $this->participant($auction, $bidder, AuctionParticipantStatus::Registered);

        $byId = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/my/participations?search='.$auction->public_id)->assertOk()->json('data');
        $this->assertCount(1, $byId);

        $byTitle = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/my/participations?search=Endpoint')->assertOk()->json('data');
        $this->assertCount(1, $byTitle);

        $noMatch = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/my/participations?search=zzzz-no-match')->assertOk()->json('data');
        $this->assertCount(0, $noMatch);
    }

    public function test_public_list_filters_and_sorting_are_applied(): void
    {
        $live = $this->auction();
        $scheduled = $this->auction(AuctionStatus::Scheduled);

        $liveRows = $this->getJson('/api/auctions?phase=live')->assertOk()->json('data');
        $this->assertSame([$live->public_id], array_column($liveRows, 'id'));

        $upcoming = $this->getJson('/api/auctions?phase=upcoming')->assertOk()->json('data');
        $this->assertSame([$scheduled->public_id], array_column($upcoming, 'id'));

        $byStatus = $this->getJson('/api/auctions?status=scheduled')->assertOk()->json('data');
        $this->assertSame([$scheduled->public_id], array_column($byStatus, 'id'));

        $bySearch = $this->getJson('/api/auctions?search='.$live->public_id)->assertOk()->json('data');
        $this->assertSame([$live->public_id], array_column($bySearch, 'id'));

        $this->getJson('/api/auctions?status=not-a-status')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_public_payment_methods_hide_transfer_details(): void
    {
        $this->paymentMethod();

        $rows = $this->getJson('/api/soom/payment-methods')->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        $this->assertArrayNotHasKey('identifier_value', $rows[0]);
        $this->assertArrayNotHasKey('recipient_name', $rows[0]);
        $this->assertArrayNotHasKey('instructions', $rows[0]);
    }

    public function test_purpose_scoped_payment_methods_require_a_real_obligation(): void
    {
        $this->paymentMethod();
        $auction = $this->auction();
        $stranger = $this->user();

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/soom/auctions/'.$auction->public_id.'/payment-methods?purpose=bidder_deposit')
            ->assertStatus(403)
            ->assertJsonPath('code', 'registration_required');

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
            ->assertCreated();

        $rows = $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/soom/auctions/'.$auction->public_id.'/payment-methods?purpose=bidder_deposit')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('identifier_value', $rows[0]);
        $this->assertArrayHasKey('instructions', $rows[0]);
    }

    public function test_bidder_cannot_read_seller_or_winner_payment_details(): void
    {
        $this->paymentMethod();
        $auction = $this->auction();
        $bidder = $this->user();
        $this->participant($auction, $bidder, AuctionParticipantStatus::Registered);

        $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/auctions/'.$auction->public_id.'/payment-methods?purpose=seller_deposit')
            ->assertStatus(403)
            ->assertJsonPath('code', 'payment_target_owner_mismatch');

        $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/soom/auctions/'.$auction->public_id.'/payment-methods?purpose=winner_payment')
            ->assertStatus(403)
            ->assertJsonPath('code', 'payment_target_owner_mismatch');
    }

    public function test_my_refunds_list_is_scoped_to_the_user(): void
    {
        $auction = $this->auction();
        $owner = $this->user();
        $other = $this->user();

        $this->refund($auction, $owner, 'succeeded');
        $this->refund($auction, $other, 'pending');

        $rows = $this->actingAs($owner, 'sanctum')->getJson('/api/soom/my/refunds')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('succeeded', $rows[0]['status']);
        $this->assertNotNull($rows[0]['status_label']);
        $this->assertSame($auction->public_id, $rows[0]['auction']['id']);
        $this->assertFalse($rows[0]['requires_user_action']);
    }

    public function test_my_refunds_status_filter_is_applied(): void
    {
        $auction = $this->auction();
        $owner = $this->user();
        $this->refund($auction, $owner, 'succeeded');
        $this->refund($auction, $owner, 'failed');

        $rows = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/soom/my/refunds?status=failed')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['requires_user_action']);
    }

    public function test_dispute_reads_are_limited_to_the_parties(): void
    {
        $auction = $this->auction(AuctionStatus::HandoverPending);
        $seller = User::findOrFail($auction->seller_id);
        $winner = $this->user();
        $participant = $this->participant($auction, $winner, AuctionParticipantStatus::Qualified);
        $bid = $this->bid($auction, $participant, $winner);
        $this->settlement($auction, $bid, SettlementStatus::Paid);

        $dispute = AuctionDispute::create([
            'auction_id' => $auction->id,
            'opened_by' => $winner->id,
            'status' => 'open',
            'reason' => 'not delivered',
            'opened_at' => now()->subHour(),
        ]);

        $rows = $this->actingAs($winner, 'sanctum')
            ->getJson('/api/soom/auctions/'.$auction->public_id.'/disputes')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('not delivered', $rows[0]['reason']);

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/soom/auctions/'.$auction->public_id.'/disputes/'.$dispute->public_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/soom/auctions/'.$auction->public_id.'/disputes')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_support_contact_returns_only_enabled_channels(): void
    {
        config()->set('support.contact', [
            'whatsapp' => '+962790000000',
            'phone' => '',
            'email' => null,
            'availability' => '9-17',
        ]);

        $data = $this->getJson('/api/soom/support-contact')->assertOk()->json('data');

        $this->assertSame('+962790000000', $data['whatsapp']);
        $this->assertNull($data['phone']);
        $this->assertNull($data['email']);
        $this->assertSame('9-17', $data['availability']);
    }

    public function test_admin_can_save_support_contact_and_it_overrides_the_environment_fallback(): void
    {
        config()->set('support.contact', [
            'whatsapp' => '+962790000000',
            'phone' => '+962780000000',
            'email' => 'env@example.test',
            'availability' => 'env hours',
        ]);

        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/support-contact?market=jo')
            ->assertOk()
            ->assertJsonPath('data.stored.phone', null)
            ->assertJsonPath('data.effective.phone', '+962780000000');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/auctions/support-contact?market=jo', [
                'whatsapp' => '+962799999999',
                'phone' => null,
                'email' => 'help@soom.test',
                'availability' => 'Sun-Thu 9:00-17:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.stored.whatsapp', '+962799999999')
            ->assertJsonPath('data.effective.whatsapp', '+962799999999')
            ->assertJsonPath('data.effective.phone', '+962780000000')
            ->assertJsonPath('data.updated_by.id', $admin->id);

        $data = $this->getJson('/api/soom/support-contact')->assertOk()->json('data');

        $this->assertSame('+962799999999', $data['whatsapp']);
        $this->assertSame('help@soom.test', $data['email']);
        $this->assertSame('Sun-Thu 9:00-17:00', $data['availability']);
        $this->assertSame('+962780000000', $data['phone']);
    }

    public function test_support_contact_rejects_invalid_values_and_denies_non_admins(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson('/api/admin/auctions/support-contact?market=jo', [
                'email' => 'not-an-email',
                'phone' => 'call-us',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone']);

        $this->actingAs($this->user('user'), 'sanctum')
            ->getJson('/api/admin/auctions/support-contact?market=jo')
            ->assertStatus(403);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Bank transfer',
            'code' => 'bank-'.Str::ulid(),
            'recipient_name' => 'Soom LLC',
            'identifier_type' => 'iban',
            'identifier_value' => 'JO94CBJO0010000000000131000302',
            'instructions' => 'Transfer the exact amount.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
    }

    private function refund(Auction $auction, User $user, string $status): void
    {
        \App\Models\Auction\RefundTransaction::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => $status,
            'amount_minor' => 10_000,
            'held_refund_amount_minor' => 10_000,
            'applied_refund_amount_minor' => 0,
            'currency_code' => 'JOD',
            'reason' => 'auction settlement refund',
            'provider' => 'manual',
            'idempotency_key' => 'refund-'.Str::ulid(),
            'attempt_count' => 1,
        ]);
    }

    private function auction(AuctionStatus $status = AuctionStatus::Live): Auction
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

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'ep-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create(['name' => 'ep-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Endpoint auction '.Str::ulid(),
            'description' => 'Endpoint auction.',
            'status' => $status,
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

        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction->refresh(), $seller->id);

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

    private function bid(Auction $auction, AuctionParticipant $participant, User $user): AuctionBid
    {
        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'ep-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return $bid;
    }

    private function settlement(Auction $auction, AuctionBid $bid, SettlementStatus $status): AuctionSettlement
    {
        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => $status,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 0,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 100_000,
            'amount_paid_minor' => $status === SettlementStatus::Paid ? 100_000 : 0,
            'remaining_amount_minor' => $status === SettlementStatus::Paid ? 0 : 100_000,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->addDay(),
            'handover_due_at' => now()->addDays(3),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "ep-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
