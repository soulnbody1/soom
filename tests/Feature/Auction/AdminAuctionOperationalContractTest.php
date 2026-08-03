<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\AuctionSettlement;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsAuctionDeadlineFixtures;
use Tests\TestCase;

final class AdminAuctionOperationalContractTest extends TestCase
{
    use BuildsAuctionDeadlineFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_admin_detail_exposes_deadlines_flags_and_snapshot(): void
    {
        [$auction, $settlement] = $this->settledAuction();
        $settlement->forceFill([
            'payment_due_at' => Carbon::now()->subHour(),
            'payment_grace_ends_at' => Carbon::now()->addHour(),
        ])->save();

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id)
            ->assertOk();

        $response->assertJsonStructure(['data' => [
            'deadlines' => [
                'seller_deposit_due_at',
                'winner_payment_due_at',
                'winner_payment_grace_ends_at',
                'handover_due_at',
                'winner_payment_reminder_hours',
                'handover_reminder_hours',
                'review_sla_minutes',
            ],
            'operational_flags' => [
                'is_seller_deposit_overdue',
                'is_payment_overdue',
                'is_payment_grace_expired',
                'is_handover_overdue',
                'has_open_dispute',
            ],
            'next_admin_action',
            'configuration_snapshot' => ['snapshot_hash', 'winner_payment_grace_period_minutes', 'platform_fee'],
        ]]);

        $this->assertTrue($response->json('data.operational_flags.is_payment_overdue'));
        $this->assertFalse($response->json('data.operational_flags.is_payment_grace_expired'));
        $this->assertSame([24, 6, 1], $response->json('data.deadlines.winner_payment_reminder_hours'));
    }

    public function test_admin_detail_carries_the_flat_fields_the_dashboard_binds_to(): void
    {
        [$auction] = $this->settledAuction();

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id)
            ->assertOk();

        $response->assertJsonStructure(['data' => [
            'extension' => ['window_seconds', 'duration_seconds', 'maximum_count', 'count'],
            'status',
            'status_label',
            'currency_code',
            'current_amount',
            'winner_payment_deadline_hours',
            'handover_deadline_hours',
        ]]);

        $this->assertIsInt($response->json('data.extension.count'));
    }

    public function test_user_detail_nests_extension_under_timeline_so_the_admin_route_is_required(): void
    {
        [$auction] = $this->settledAuction();

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk();

        $this->assertNull($response->json('data.extension'));
        $this->assertIsInt($response->json('data.timeline.extension.count'));
    }

    public function test_next_admin_action_points_to_winner_default_after_grace(): void
    {
        [$auction, $settlement] = $this->settledAuction();
        $settlement->forceFill([
            'payment_due_at' => Carbon::now()->subDays(2),
            'payment_grace_ends_at' => Carbon::now()->subHour(),
        ])->save();

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id)
            ->assertOk();

        $this->assertSame('mark_winner_defaulted', $response->json('data.next_admin_action'));
    }

    public function test_overdue_payment_filter_is_applied_server_side(): void
    {
        [$overdueAuction, $overdueSettlement] = $this->settledAuction();
        $overdueSettlement->forceFill(['payment_due_at' => Carbon::now()->subDay()])->save();

        [, $healthySettlement] = $this->settledAuction();
        $healthySettlement->forceFill(['payment_due_at' => Carbon::now()->addDay()])->save();

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?overdue_payment=1')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($overdueAuction->public_id, $response->json('data.0.id'));
    }

    public function test_awaiting_seller_deposit_filter_is_applied_server_side(): void
    {
        [$awaiting] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $this->auction(AuctionStatus::Live);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?awaiting_seller_deposit=1')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($awaiting->public_id, $response->json('data.0.id'));
    }

    public function test_currency_and_phase_filters_are_applied_server_side(): void
    {
        $this->auction(AuctionStatus::Live);
        $this->auction(AuctionStatus::Completed);

        $live = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?phase=live')
            ->assertOk();
        $this->assertSame(1, $live->json('total'));

        $byCurrency = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?currency=JOD')
            ->assertOk();
        $this->assertSame(2, $byCurrency->json('total'));

        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?currency=USD')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_settlement_resource_exposes_reminder_and_default_metadata(): void
    {
        [$auction, $settlement] = $this->settledAuction();
        $settlement->forceFill([
            'payment_reminders_sent' => [24, 6],
            'handover_reminders_sent' => ['seller' => [24]],
        ])->save();

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id)
            ->assertOk();

        $settlementPayload = $response->json('data.financial_details.settlement');

        $this->assertSame([24, 6], $settlementPayload['payment_reminders_sent']);
        $this->assertSame(['seller' => [24]], $settlementPayload['handover_reminders_sent']);
        $this->assertFalse($settlementPayload['auto_defaulted']);
        $this->assertArrayHasKey('payment_grace_ends_at', $settlementPayload);
        $this->assertArrayHasKey('is_handover_overdue', $settlementPayload);
    }

    private function settledAuction(): array
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [, $participant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $participant, $participant->user, 100_000, 1);

        app(FinalizeAuctionAction::class)->execute($auction->refresh());

        $settlement = AuctionSettlement::where('auction_id', $auction->id)->where('is_current', true)->firstOrFail();

        return [$auction->refresh(), $settlement];
    }
}
