<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionSellerPayout;
use App\Repositories\Auction\AuctionDisputeRepository;
use App\Repositories\Auction\PayoutDestinationRepository;

final class SellerPayoutRules
{
    public function __construct(
        private readonly AuctionDisputeRepository $disputes,
        private readonly PayoutDestinationRepository $destinations,
    ) {}

    public function ensureNoOpenDispute(AuctionSellerPayout $payout): void
    {
        if ($this->disputes->hasOpenDispute((int) $payout->auction_id)) {
            throw AuctionException::domain('payout_blocked_by_dispute');
        }
    }

    /**
     * Snapshot destination data onto the payout if missing; fails when the
     * seller has no usable destination and no override was provided.
     *
     * @param  array{recipient_name?: string|null, identifier_type?: string|null, identifier_value?: string|null}  $override
     */
    public function ensureDestinationSnapshot(AuctionSellerPayout $payout, array $override = []): void
    {
        $overrideComplete = ($override['recipient_name'] ?? null)
            && ($override['identifier_type'] ?? null)
            && ($override['identifier_value'] ?? null);

        if ($overrideComplete) {
            $payout->forceFill([
                'recipient_name' => $override['recipient_name'],
                'identifier_type' => $override['identifier_type'],
                'identifier_value' => $override['identifier_value'],
            ]);

            return;
        }

        if ($payout->hasDestinationSnapshot()) {
            return;
        }

        $default = $this->destinations->defaultFor((int) $payout->seller_id);
        if ($default === null) {
            throw AuctionException::domain('payout_destination_missing');
        }

        $payout->forceFill([
            'destination_id' => $default->id,
            'recipient_name' => $default->recipient_name,
            'identifier_type' => $default->identifier_type,
            'identifier_value' => $default->identifier_value,
        ]);
    }
}
