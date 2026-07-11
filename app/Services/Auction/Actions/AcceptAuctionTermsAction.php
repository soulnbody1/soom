<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionTermsRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class AcceptAuctionTermsAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionTermsRepository $terms,
    ) {}

    public function execute(Auction $auction, int $userId, ?string $ipAddress, ?string $userAgent): AuctionTermsAcceptance
    {
        return $this->transaction->run(function () use ($auction, $userId, $ipAddress, $userAgent): AuctionTermsAcceptance {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $participant = $this->participants->findByAuctionAndUser($auction->id, $userId);

            if (! $participant) {
                throw new AuctionException(__('auction.errors.terms_registration_required'));
            }

            if (! $auction->terms_version_id) {
                throw new AuctionException(__('auction.errors.terms_missing'));
            }

            $acceptance = $this->terms->firstOrCreateAcceptance(
                [
                    'auction_id' => $auction->id,
                    'user_id' => $userId,
                    'terms_version_id' => $auction->terms_version_id,
                ],
                [
                    'participant_id' => $participant->id,
                    'ip_hash' => $ipAddress ? hash('sha256', $ipAddress.config('app.key')) : null,
                    'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
                    'accepted_at' => Carbon::now(),
                ]
            );

            $this->audit->log('auction.terms_accepted', $auction, $userId, 'user', [
                'terms_version_id' => $auction->terms_version_id,
            ]);

            return $acceptance->refresh();
        });
    }
}
