<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Services\Auction\Payments\PaymentProviderFactory;
use Throwable;

final class DescribePaymentProvidersAction
{
    public function __construct(
        private readonly PaymentProviderFactory $providers,
    ) {}

    public function execute(): array
    {
        $described = [];

        foreach ($this->providers->registeredCodes() as $code) {
            $described[$code] = $this->describe($code);
        }

        return $described;
    }

    public function describe(string $code): array
    {
        $enabled = $this->providers->isEnabled($code);
        $credentials = $this->providers->hasCredentials($code);

        $status = [
            'code' => $code,
            'enabled' => $enabled,
            'credentials_configured' => $credentials,
            'capabilities' => null,
            'status' => 'unavailable',
        ];

        if (! $enabled) {
            return $status;
        }

        try {
            $capabilities = $this->providers->make($code)->capabilities();
        } catch (Throwable) {
            return $status;
        }

        return array_replace($status, [
            'status' => $credentials ? 'ready' : 'missing_credentials',
            'capabilities' => [
                'amount_format' => $capabilities->amountFormat->value,
                'currencies' => $capabilities->supportedCurrencies,
                'refund' => $capabilities->supportsRefund,
                'partial_refund' => $capabilities->supportsPartialRefund,
                'inquiry' => $capabilities->supportsInquiry,
                'webhook' => $capabilities->supportsWebhook,
            ],
        ]);
    }
}
