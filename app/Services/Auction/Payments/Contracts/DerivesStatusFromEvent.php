<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Contracts;

use App\Services\Auction\Payments\ProviderEvent;
use App\Services\Auction\Payments\ProviderPaymentStatus;

/**
 * Implemented by providers that expose no status inquiry API, where a verified
 * inbound event is the only server-to-server statement of what happened.
 *
 * Only consulted when capabilities()->supportsInquiry is false.
 */
interface DerivesStatusFromEvent
{
    public function statusFromEvent(ProviderEvent $event): ProviderPaymentStatus;
}
