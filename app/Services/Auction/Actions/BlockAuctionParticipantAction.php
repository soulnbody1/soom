<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionParticipant;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class BlockAuctionParticipantAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionSettlementRepository $settlements,
    ) {}

    public function block(Auction $auction, AuctionParticipant $participant, int $adminId, string $reason): AuctionParticipant
    {
        if (trim($reason) === '') {
            throw AuctionException::domain('participant_block_reason_required');
        }

        return $this->transaction->run(function () use ($auction, $participant, $adminId, $reason): AuctionParticipant {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $locked = $this->participants->lockParticipant($auction->id, (int) $participant->user_id);

            if (! $locked) {
                throw AuctionException::domain('participant_not_found');
            }

            $settlement = $this->settlements->lockCurrentSettlementForPayment($auction->id);

            if ($settlement && (int) $settlement->winner_id === (int) $locked->user_id) {
                throw AuctionException::domain('participant_block_winner_not_allowed');
            }

            if ($locked->status === AuctionParticipantStatus::Blocked) {
                return $locked;
            }

            $locked->forceFill([
                'status' => AuctionParticipantStatus::Blocked,
                'blocked_at' => Carbon::now(),
                'block_reason' => $reason,
            ]);
            $this->participants->save($locked);

            $this->audit->log('auction.participant_blocked', $auction, $adminId, 'admin', [
                'participant_public_id' => $locked->public_id,
                'user_id' => $locked->user_id,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    public function unblock(Auction $auction, AuctionParticipant $participant, int $adminId, string $reason): AuctionParticipant
    {
        return $this->transaction->run(function () use ($auction, $participant, $adminId, $reason): AuctionParticipant {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $locked = $this->participants->lockParticipant($auction->id, (int) $participant->user_id);

            if (! $locked) {
                throw AuctionException::domain('participant_not_found');
            }

            if ($locked->status !== AuctionParticipantStatus::Blocked) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $locked->qualified_at === null
                    ? AuctionParticipantStatus::Registered
                    : AuctionParticipantStatus::Qualified,
                'blocked_at' => null,
                'block_reason' => null,
            ]);
            $this->participants->save($locked);

            $this->audit->log('auction.participant_unblocked', $auction, $adminId, 'admin', [
                'participant_public_id' => $locked->public_id,
                'user_id' => $locked->user_id,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
