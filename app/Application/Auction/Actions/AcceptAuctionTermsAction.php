<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionAudit;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use Illuminate\Support\Carbon;

final class AcceptAuctionTermsAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit
    ) {}

    public function execute(Auction $auction, int $userId, ?string $ipAddress, ?string $userAgent): AuctionTermsAcceptance
    {
        return $this->transaction->run(function () use ($auction, $userId, $ipAddress, $userAgent): AuctionTermsAcceptance {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $participant = AuctionParticipant::where('auction_id', $auction->id)
                ->where('user_id', $userId)
                ->first();

            if (! $participant) {
                throw new AuctionException('Participant registration is required before accepting terms.');
            }

            if (! $auction->terms_version_id) {
                throw new AuctionException('Auction has no terms version.');
            }

            $acceptance = AuctionTermsAcceptance::firstOrCreate(
                [
                    'auction_id' => $auction->id,
                    'user_id' => $userId,
                    'terms_version_id' => $auction->terms_version_id,
                ],
                [
                    'participant_id' => $participant->id,
                    'ip_hash' => $ipAddress ? hash('sha256', $ipAddress . config('app.key')) : null,
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
