<?php

declare(strict_types=1);

namespace App\Services\Auction\Refunds;

use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Payments\PaymentProviderFactory;
use Illuminate\Contracts\Container\Container;
use Throwable;

final class RefundProcessorFactory
{
    public function __construct(
        private readonly Container $container,
        private readonly PaymentProviderFactory $providers,
    ) {}

    public function forRefund(RefundTransaction $refund): AuctionRefundProcessorInterface
    {
        $provider = (string) $refund->provider;

        if ($provider === '' || $provider === 'manual' || ! $this->providers->isRegistered($provider)) {
            return $this->container->make(AuctionRefundProcessorInterface::class);
        }

        try {
            $supportsRefund = $this->providers->make($provider)->capabilities()->supportsRefund;
        } catch (Throwable) {
            return $this->container->make(AuctionRefundProcessorInterface::class);
        }

        return $supportsRefund
            ? $this->container->make(ProviderRefundProcessor::class)
            : $this->container->make(AuctionRefundProcessorInterface::class);
    }
}
