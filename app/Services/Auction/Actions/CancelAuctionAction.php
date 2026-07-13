<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionCancellationTrigger;
use App\DTO\Auction\AuctionCancellationContextDTO;
use App\Models\Auction\Auction;

final class CancelAuctionAction
{
    public function __construct(
        private readonly CancelAuctionFinanciallyAction $financialCancellation,
    ) {}

    public function execute(
        Auction $auction,
        int $actorId,
        string $actorType,
        string $reason,
        ?string $reasonCode = null,
        ?string $liability = null,
    ): Auction {
        return $this->financialCancellation->execute(
            AuctionCancellationContextDTO::fromLegacy(
                $this->triggerFor($actorType, $reason, $reasonCode),
                $auction->id,
                $actorId,
                $actorType,
                $reason,
                $reasonCode,
                $liability
            )
        );
    }

    private function triggerFor(string $actorType, string $reason, ?string $reasonCode = null): AuctionCancellationTrigger
    {
        $reasonCode = strtolower((string) $reasonCode);
        $reason = strtolower($reason);

        if ($actorType === 'user') {
            return AuctionCancellationTrigger::SellerRequested;
        }

        if ($actorType === 'system') {
            return AuctionCancellationTrigger::SystemTriggered;
        }

        return match (true) {
            $reasonCode === 'platform_fault' => AuctionCancellationTrigger::PlatformFault,
            $reasonCode === 'seller_breach' => AuctionCancellationTrigger::SellerBreach,
            $reasonCode === 'fraud' => AuctionCancellationTrigger::Fraud,
            $reasonCode === 'compliance' => AuctionCancellationTrigger::Compliance,
            $reasonCode === 'buyer_fault' => AuctionCancellationTrigger::BuyerFault,
            str_contains($reason, 'platform_fault'), str_contains($reason, 'platform fault') => AuctionCancellationTrigger::PlatformFault,
            str_contains($reason, 'seller_fault'), str_contains($reason, 'seller fault'), str_contains($reason, 'seller_breach') => AuctionCancellationTrigger::SellerBreach,
            str_contains($reason, 'fraud') => AuctionCancellationTrigger::Fraud,
            str_contains($reason, 'compliance') => AuctionCancellationTrigger::Compliance,
            default => AuctionCancellationTrigger::AdminRequested,
        };
    }
}
