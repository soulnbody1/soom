<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\WinnerDefaultDepositDisposition;
use App\DTO\Auction\WinnerDefaultDepositDispositionDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;

final class WinnerDefaultDepositDispositionResolver
{
    public function __construct(
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
    ) {}

    public function resolve(Auction $auction, ?AuctionDeposit $deposit, string $reason, array $context = []): WinnerDefaultDepositDispositionDTO
    {
        if (! $deposit || ((int) $deposit->held_amount_minor + (int) $deposit->applied_amount_minor) <= 0) {
            return new WinnerDefaultDepositDispositionDTO(
                WinnerDefaultDepositDisposition::NoAction,
                'winner_default',
                trim($reason) !== '' ? trim($reason) : 'winner_default'
            );
        }

        $configured = $context['winner_default_deposit_disposition'] ?? $this->configuredDisposition($auction);
        $disposition = $this->normalizeDisposition((string) $configured);
        $forfeitAmount = $disposition === WinnerDefaultDepositDisposition::PartialForfeit
            ? $this->partialForfeitAmount($auction, $context)
            : 0;

        if ($disposition === WinnerDefaultDepositDisposition::PartialForfeit && $forfeitAmount <= 0) {
            $disposition = WinnerDefaultDepositDisposition::ManualReview;
        }

        return new WinnerDefaultDepositDispositionDTO(
            $disposition,
            'winner_default',
            trim($reason) !== '' ? trim($reason) : 'winner_default',
            $forfeitAmount
        );
    }

    private function configuredDisposition(Auction $auction): string
    {
        return $this->snapshotReader->forAuction($auction)->winnerDefaultDepositDisposition();
    }

    private function partialForfeitAmount(Auction $auction, array $context): int
    {
        return (int) (
            $context['forfeit_amount_minor']
            ?? $this->snapshotReader->forAuction($auction)->winnerDefaultDepositForfeitAmount()
        );
    }

    private function normalizeDisposition(string $value): WinnerDefaultDepositDisposition
    {
        return match (strtolower(trim($value))) {
            'forfeit',
            'full_forfeit' => WinnerDefaultDepositDisposition::FullForfeit,
            'partial_forfeit' => WinnerDefaultDepositDisposition::PartialForfeit,
            'refund' => WinnerDefaultDepositDisposition::Refund,
            'manual_review' => WinnerDefaultDepositDisposition::ManualReview,
            'no_action' => WinnerDefaultDepositDisposition::NoAction,
            default => WinnerDefaultDepositDisposition::ManualReview,
        };
    }
}
