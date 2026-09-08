<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Fees;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\PaymentMethod;
use InvalidArgumentException;

final class CustomerFeePolicy
{
    public function feeFor(PaymentMethod $method, int $principalMinor): int
    {
        $schedule = $this->scheduleFor($method);

        if ($schedule === null) {
            return 0;
        }

        try {
            return $schedule->feeFor($principalMinor);
        } catch (CustomerFeeTierMissing) {
            throw AuctionException::domain('payment_fee_tier_missing');
        }
    }

    public function scheduleFor(PaymentMethod $method): ?CustomerFeeSchedule
    {
        if (! CustomerFeeSchedule::isConfigured($method->fee_basis, $method->fee_tiers)) {
            return null;
        }

        try {
            return CustomerFeeSchedule::fromConfiguration($method->fee_basis, $method->fee_tiers);
        } catch (InvalidArgumentException) {
            throw AuctionException::domain('payment_fee_schedule_invalid');
        }
    }
}
