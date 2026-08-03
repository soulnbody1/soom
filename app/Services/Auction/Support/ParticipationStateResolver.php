<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\BidBlockingReason;
use App\Domain\Auction\Enums\ParticipationDepositStatus;
use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotIncompleteException;
use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotMissingException;
use App\DTO\Auction\BidEligibilityContextDTO;
use App\DTO\Auction\ParticipationStateDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\User;
use App\Repositories\Auction\Queries\ViewerAuctionContext;
use App\Repositories\Auction\Queries\ViewerAuctionContextQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ParticipationStateResolver
{
    public function __construct(
        private readonly ViewerAuctionContextQuery $contextQuery,
        private readonly BidEligibilityLadder $ladder,
        private readonly NextActionResolver $nextAction,
        private readonly AuctionConfigurationSnapshotReader $snapshots,
    ) {}

    public function forAuction(Auction $auction, ?User $viewer): ParticipationStateDTO
    {
        $this->forCollection([$auction], $viewer);

        return $auction->participationState;
    }

    public function forCollection(iterable $auctions, ?User $viewer): void
    {
        $auctions = collect($auctions)->filter(fn ($auction) => $auction instanceof Auction)->values();

        if ($auctions->isEmpty()) {
            return;
        }

        $context = $this->contextQuery->load(
            $auctions->pluck('id')->all(),
            $viewer?->id
        );

        $snapshots = [];
        $termsVersionIds = [];
        foreach ($auctions as $auction) {
            $snapshot = $this->snapshot($auction);
            $snapshots[$auction->id] = $snapshot;
            $termsVersionId = $this->termsVersionId($auction, $snapshot);
            if ($termsVersionId !== null) {
                $termsVersionIds[$termsVersionId] = $termsVersionId;
            }
        }

        $termsPublicIds = $termsVersionIds === []
            ? collect()
            : AuctionTermsVersion::whereIn('id', array_values($termsVersionIds))
                ->pluck('public_id', 'id');

        foreach ($auctions as $auction) {
            $auction->participationState = $this->build(
                $auction,
                $viewer,
                $context,
                $snapshots[$auction->id],
                $termsPublicIds
            );
        }
    }

    private function build(
        Auction $auction,
        ?User $viewer,
        ViewerAuctionContext $context,
        ?AuctionConfigurationSnapshot $snapshot,
        Collection $termsPublicIds
    ): ParticipationStateDTO {
        $depositRequired = $this->depositRequiredMinor($auction, $snapshot);
        $termsVersionId = $this->termsVersionId($auction, $snapshot);
        $termsPublicId = $termsVersionId === null ? null : $termsPublicIds->get($termsVersionId);

        if ($viewer === null) {
            return ParticipationStateDTO::guest($depositRequired, (string) $auction->currency_code, $termsPublicId);
        }

        $auctionId = (int) $auction->id;
        $participant = $context->participant($auctionId);
        $deposit = $context->bidderDeposit($auctionId);
        $settlement = $context->settlement($auctionId);
        $openDispute = $context->openDispute($auctionId);
        $isSeller = (int) $auction->seller_id === (int) $viewer->id;
        $termsAccepted = $context->hasAcceptedTerms($auctionId, $termsVersionId);

        $blockingReason = $this->ladder->evaluate(new BidEligibilityContextDTO(
            viewerId: (int) $viewer->id,
            sellerId: (int) $auction->seller_id,
            status: $auction->status,
            startsAt: $auction->starts_at,
            endsAt: $auction->ends_at,
            now: Carbon::now(),
            configurationAvailable: $snapshot !== null,
            participantStatus: $participant?->status,
            hasAcceptedTerms: $termsAccepted,
            requiredTermsVersionId: $termsVersionId,
            depositStatus: $deposit?->status,
            depositHeldMinor: (int) ($deposit->held_amount_minor ?? 0),
            depositRequiredMinor: $depositRequired,
        ));

        [$nextAction, $nextActionAllowed] = $this->nextAction->resolve(
            $auction,
            $viewer,
            $blockingReason,
            $settlement,
            $openDispute !== null
        );

        return new ParticipationStateDTO(
            isRegistered: $participant !== null,
            participantStatus: $participant?->status,
            registeredAt: $participant?->registered_at?->toIso8601String(),
            qualifiedAt: $participant?->qualified_at?->toIso8601String(),
            termsAccepted: $termsAccepted,
            termsAcceptedAt: $context->termsAcceptedAt($auctionId, $termsVersionId),
            requiredTermsVersionId: $termsPublicId,
            depositRequiredMinor: $depositRequired,
            currencyCode: (string) $auction->currency_code,
            depositStatus: ParticipationDepositStatus::resolve($deposit, $depositRequired),
            canBid: $blockingReason === BidBlockingReason::None,
            blockingReason: $blockingReason,
            isHighestBidder: $this->isHighestBidder($auction, (int) $viewer->id),
            myHighestBidMinor: $context->highestBidMinor($auctionId),
            myBidsCount: $context->bidCount($auctionId),
            isWinner: $settlement !== null && (int) $settlement->winner_id === (int) $viewer->id,
            isSeller: $isSeller,
            nextAction: $nextAction,
            nextActionAllowed: $nextActionAllowed,
        );
    }

    private function snapshot(Auction $auction): ?AuctionConfigurationSnapshot
    {
        try {
            return $this->snapshots->forAuction($auction);
        } catch (AuctionConfigurationSnapshotMissingException|AuctionConfigurationSnapshotIncompleteException) {
            return null;
        }
    }

    private function depositRequiredMinor(Auction $auction, ?AuctionConfigurationSnapshot $snapshot): int
    {
        return (int) ($snapshot->bidder_deposit_required_minor ?? $auction->bidder_deposit_amount_minor);
    }

    private function termsVersionId(Auction $auction, ?AuctionConfigurationSnapshot $snapshot): ?int
    {
        $versionId = $snapshot->terms_version_id ?? $auction->terms_version_id;

        return $versionId === null ? null : (int) $versionId;
    }

    private function isHighestBidder(Auction $auction, int $viewerId): bool
    {
        if (! $auction->relationLoaded('currentLeadingBid')) {
            return false;
        }

        return (int) ($auction->currentLeadingBid?->bidder_id ?? 0) === $viewerId;
    }
}
