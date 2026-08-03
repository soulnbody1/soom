<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionParticipant;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionTermsRepository;
use Illuminate\Support\Carbon;

final class ParticipantQualifier
{
    public function __construct(
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionTermsRepository $terms,
        private readonly AuctionAudit $audit,
    ) {}

    public function qualifyWhenNoDepositRequired(
        Auction $auction,
        AuctionParticipant $participant,
        AuctionConfigurationSnapshot $snapshot
    ): AuctionParticipant {
        if ((int) $snapshot->bidder_deposit_required_minor > 0) {
            return $participant;
        }

        if ($participant->status !== AuctionParticipantStatus::Registered) {
            return $participant;
        }

        if (! $snapshot->terms_version_id) {
            return $participant;
        }

        $accepted = $this->terms->hasAcceptedTerms(
            $auction->id,
            (int) $participant->user_id,
            (int) $snapshot->terms_version_id
        );

        if (! $accepted) {
            return $participant;
        }

        $participant->forceFill([
            'status' => AuctionParticipantStatus::Qualified,
            'qualified_at' => Carbon::now(),
        ]);
        $this->participants->save($participant);

        $this->audit->log('auction.participant_qualified', $auction, (int) $participant->user_id, 'system', [
            'participant_public_id' => $participant->public_id,
            'reason' => 'zero_bidder_deposit',
        ]);

        return $participant;
    }
}
