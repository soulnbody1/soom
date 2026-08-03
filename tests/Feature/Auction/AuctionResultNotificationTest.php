<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Jobs\Auction\DispatchAuctionOutboxJob;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\OutboxMessage;
use App\Models\User;
use App\Services\Auction\Actions\DispatchOutboxMessagesAction;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsAuctionDeadlineFixtures;
use Tests\TestCase;

final class AuctionResultNotificationTest extends TestCase
{
    use BuildsAuctionDeadlineFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_losing_bidders_are_notified_without_revealing_the_winner(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [$winner, $winnerParticipant] = $this->qualifiedParticipant($auction, 10_000);
        [$loser, $loserParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $loserParticipant, $loser, 90_000, 1);
        $this->bid($auction, $winnerParticipant, $winner, 100_000, 2);

        app(FinalizeAuctionAction::class)->execute($auction->refresh());
        $this->dispatchOutbox();

        $notification = $this->notificationOf($loser, 'auction.bidder_lost');

        $this->assertNotNull($notification);
        $this->assertSame($auction->public_id, $notification->data['auction_id']);
        $this->assertStringNotContainsString((string) $winner->name, (string) $notification->data['message']);
        $this->assertStringNotContainsString((string) $winner->email, (string) $notification->data['message']);
        $this->assertArrayNotHasKey('winner_id', $notification->data);
    }

    public function test_loss_notification_deep_links_to_refunds_when_deposit_is_refund_pending(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [$winner, $winnerParticipant] = $this->qualifiedParticipant($auction, 10_000);
        [$loser, $loserParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $loserParticipant, $loser, 90_000, 1);
        $this->bid($auction, $winnerParticipant, $winner, 100_000, 2);

        app(FinalizeAuctionAction::class)->execute($auction->refresh());
        $this->dispatchOutbox();

        $notification = $this->notificationOf($loser, 'auction.bidder_lost');
        $depositStatus = AuctionDeposit::where('auction_id', $auction->id)
            ->where('user_id', $loser->id)
            ->value('status');

        $this->assertNotNull($notification);
        $this->assertSame($depositStatus->value, $notification->data['deposit_status']);
        $this->assertContains($notification->data['screen'], ['auction_refunds', 'auction_details']);
    }

    public function test_winner_receives_win_notification_and_losers_do_not(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [$winner, $winnerParticipant] = $this->qualifiedParticipant($auction, 10_000);
        [$loser, $loserParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $loserParticipant, $loser, 90_000, 1);
        $this->bid($auction, $winnerParticipant, $winner, 100_000, 2);

        app(FinalizeAuctionAction::class)->execute($auction->refresh());
        $this->dispatchOutbox();

        $this->assertNotNull($this->notificationOf($winner, 'auction.finalized'));
        $this->assertSame(0, $winner->notifications()->where('data->event_type', 'auction.bidder_lost')->count());
        $this->assertSame(0, $loser->notifications()->where('data->event_type', 'auction.finalized')->count());
    }

    public function test_unsold_auction_notifies_every_bidder(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        $auction->forceFill(['reserve_amount_minor' => 500_000])->save();
        [$bidderA, $participantA] = $this->qualifiedParticipant($auction, 10_000);
        [$bidderB, $participantB] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $participantA, $bidderA, 90_000, 1);
        $this->bid($auction, $participantB, $bidderB, 100_000, 2);

        app(FinalizeAuctionAction::class)->execute($auction->refresh());
        $this->dispatchOutbox();

        $this->assertSame(AuctionStatus::Unsold, $auction->refresh()->status);

        foreach ([$bidderA, $bidderB] as $bidder) {
            $this->assertNotNull($this->notificationOf($bidder, 'auction.unsold_bidders'));
        }
    }

    public function test_unsold_auction_without_bids_emits_no_bidder_event(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        $auction->forceFill(['reserve_amount_minor' => 500_000])->save();

        app(FinalizeAuctionAction::class)->execute($auction->refresh());

        $this->assertSame(0, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.unsold_bidders')
            ->count());
    }

    public function test_result_notifications_are_not_duplicated_on_replay(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [$winner, $winnerParticipant] = $this->qualifiedParticipant($auction, 10_000);
        [$loser, $loserParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $loserParticipant, $loser, 90_000, 1);
        $this->bid($auction, $winnerParticipant, $winner, 100_000, 2);

        $finalize = app(FinalizeAuctionAction::class);
        $finalize->execute($auction->refresh());
        $finalize->execute($auction->refresh());

        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.bidder_lost')
            ->count());

        $this->dispatchOutbox();
        OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.bidder_lost')
            ->update(['status' => \App\Domain\Auction\Enums\OutboxStatus::Pending, 'available_at' => Carbon::now()->subMinute()]);
        $this->dispatchOutbox();

        $this->assertSame(1, $loser->notifications()->where('data->event_type', 'auction.bidder_lost')->count());
    }

    private function dispatchOutbox(): void
    {
        app(DispatchAuctionOutboxJob::class)->handle(app(DispatchOutboxMessagesAction::class));
    }

    private function notificationOf(User $user, string $eventType): ?DatabaseNotification
    {
        return $user->notifications()->where('data->event_type', $eventType)->first();
    }
}
