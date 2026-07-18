<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\PayoutDestination;
use App\Repositories\Auction\PayoutDestinationRepository;
use App\Services\Auction\Support\AuctionTransaction;

final class SavePayoutDestinationAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly PayoutDestinationRepository $destinations,
    ) {}

    public function execute(int $userId, array $data, ?PayoutDestination $destination = null): PayoutDestination
    {
        return $this->transaction->run(function () use ($userId, $data, $destination): PayoutDestination {
            $isFirst = $destination === null && $this->destinations->listFor($userId)->isEmpty();
            $makeDefault = (bool) ($data['is_default'] ?? false) || $isFirst;

            if ($makeDefault) {
                $this->destinations->clearDefaultFor($userId);
            }

            $destination ??= new PayoutDestination(['user_id' => $userId]);

            $destination->forceFill([
                'recipient_name' => (string) $data['recipient_name'],
                'identifier_type' => (string) $data['identifier_type'],
                'identifier_value' => (string) $data['identifier_value'],
                'is_default' => $makeDefault ? true : (bool) $destination->is_default,
                'default_marker' => $makeDefault ? 1 : $destination->default_marker,
            ]);

            $this->destinations->save($destination);

            return $destination;
        });
    }
}
