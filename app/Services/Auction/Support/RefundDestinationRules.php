<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\PayoutDestinationRepository;

final class RefundDestinationRules
{
    public function __construct(
        private readonly PayoutDestinationRepository $destinations,
    ) {}

    /**
     * @param  array{recipient_name?: string|null, identifier_type?: string|null, identifier_value?: string|null}  $override
     */
    public function ensureDestinationSnapshot(RefundTransaction $refund, array $override = []): void
    {
        $overrideComplete = ($override['recipient_name'] ?? null)
            && ($override['identifier_type'] ?? null)
            && ($override['identifier_value'] ?? null);

        if ($overrideComplete) {
            $refund->forceFill([
                'destination_id' => null,
                'recipient_name' => $override['recipient_name'],
                'identifier_type' => $override['identifier_type'],
                'identifier_value' => $override['identifier_value'],
            ]);

            return;
        }

        if ($refund->hasDestinationSnapshot()) {
            return;
        }

        $default = $this->destinations->defaultFor((int) $refund->user_id);

        if ($default === null) {
            throw AuctionException::domain('refund_destination_missing');
        }

        $refund->forceFill([
            'destination_id' => $default->id,
            'recipient_name' => $default->recipient_name,
            'identifier_type' => $default->identifier_type,
            'identifier_value' => $default->identifier_value,
        ]);
    }
}
