<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\PaymentProviderEvent;

final class PaymentProviderEventRepository
{
    public function firstOrCreate(array $uniqueAttributes, array $defaults): PaymentProviderEvent
    {
        return PaymentProviderEvent::firstOrCreate($uniqueAttributes, $defaults);
    }

    public function lockById(int $eventId): PaymentProviderEvent
    {
        return PaymentProviderEvent::whereKey($eventId)->lockForUpdate()->firstOrFail();
    }

    public function save(PaymentProviderEvent $event): void
    {
        $event->save();
    }
}
