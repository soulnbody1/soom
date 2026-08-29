<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Contracts;

use App\DTO\Auction\RefundProcessingResult;
use App\Services\Auction\Payments\CheckoutInstruction;
use App\Services\Auction\Payments\PaymentIntent;
use App\Services\Auction\Payments\ProviderCapabilities;
use App\Services\Auction\Payments\ProviderEvent;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use App\Services\Auction\Payments\RefundCommand;
use Illuminate\Http\Request;

interface PaymentProvider
{
    public function code(): string;

    public function capabilities(): ProviderCapabilities;

    public function createCheckout(PaymentIntent $intent): CheckoutInstruction;

    public function fetchStatus(string $providerTransactionId): ProviderPaymentStatus;

    public function parseEvent(Request $request): ProviderEvent;

    public function refund(RefundCommand $command): RefundProcessingResult;
}
