<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers;

use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\ProviderAmountFormat;
use App\DTO\Auction\RefundProcessingResult;
use App\Services\Auction\Payments\CheckoutInstruction;
use App\Services\Auction\Payments\Contracts\PaymentProvider;
use App\Services\Auction\Payments\Contracts\VerifiesProviderConnection;
use App\Services\Auction\Payments\PaymentIntent;
use App\Services\Auction\Payments\ProviderCapabilities;
use App\Services\Auction\Payments\ProviderEvent;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use App\Services\Auction\Payments\Providers\NGenius\NGeniusClient;
use App\Services\Auction\Payments\Providers\NGenius\NGeniusOrder;
use App\Services\Auction\Payments\Providers\NGenius\NGeniusPayload;
use App\Services\Auction\Payments\Providers\NGenius\NGeniusWebhookVerifier;
use App\Services\Auction\Payments\RefundCommand;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class NGeniusPaymentProvider implements PaymentProvider, VerifiesProviderConnection
{
    public const CODE = 'ngenius';

    public function __construct(
        private readonly NGeniusClient $client,
        private readonly NGeniusWebhookVerifier $verifier,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            amountFormat: ProviderAmountFormat::MinorUnits,
            supportedCurrencies: $this->supportedCurrencies(),
            supportsRefund: true,
            supportsPartialRefund: true,
            supportsInquiry: true,
            supportsWebhook: true,
        );
    }

    public function verifyConnection(): void
    {
        if (! $this->client->isConfigured()) {
            throw new RuntimeException('N-Genius credentials are not configured.');
        }

        $this->client->accessToken(true);
    }

    public function createCheckout(PaymentIntent $intent): CheckoutInstruction
    {
        if (! $this->client->isConfigured()) {
            throw new RuntimeException('N-Genius credentials are not configured.');
        }

        $order = new NGeniusOrder($this->client->createOrder([
            'action' => 'PURCHASE',
            'amount' => [
                'currencyCode' => strtoupper($intent->currencyCode),
                'value' => $intent->amountMinor,
            ],
            'emailAddress' => $this->payerEmail($intent),
            'merchantOrderReference' => $intent->merchantReference,
            'merchantDefinedData' => [
                NGeniusPayload::REFERENCE_KEY => $intent->merchantReference,
            ],
            'merchantAttributes' => [
                'redirectUrl' => $intent->returnUrl,
                'cancelUrl' => $intent->returnUrl,
                'skipConfirmationPage' => true,
            ],
            'billingAddress' => [
                'firstName' => $this->payerFirstName($intent),
                'lastName' => $this->payerLastName($intent),
            ],
        ]));

        $reference = $order->reference();
        $paymentHref = $order->paymentHref();

        if ($reference === '' || $paymentHref === null) {
            throw new RuntimeException('N-Genius did not return a usable order.');
        }

        return new CheckoutInstruction(
            providerTransactionId: $reference,
            type: 'redirect',
            redirectUrl: $paymentHref,
            reference: $reference,
            expiresInSeconds: (int) config('auction.payments.providers.ngenius.checkout_ttl_seconds', 1800),
        );
    }

    public function fetchStatus(string $providerTransactionId): ProviderPaymentStatus
    {
        $order = new NGeniusOrder($this->client->retrieveOrder($providerTransactionId));

        return new ProviderPaymentStatus(
            status: NGeniusPayload::status($order->state()),
            providerTransactionId: $order->reference() !== '' ? $order->reference() : $providerTransactionId,
            amountMinor: $order->capturedAmountMinor(),
            currencyCode: $order->capturedCurrencyCode(),
            failureCode: $this->failureCode($order),
            providerFeeMinor: null,
            settlementReference: null,
            merchantReference: $order->merchantReference(),
            payload: $order->safeSummary(),
        );
    }

    public function parseEvent(Request $request): ProviderEvent
    {
        $verified = $this->verifier->verify($request);
        $payload = $verified ? $this->verifier->payload($request) : [];

        return new ProviderEvent(
            eventId: (string) ($payload['eventId'] ?? ''),
            eventType: (string) ($payload['eventName'] ?? ''),
            providerTransactionId: NGeniusPayload::orderReference($payload),
            signatureVerified: $verified,
            merchantReference: NGeniusPayload::merchantReference($payload),
            payload: [
                'event_id' => $payload['eventId'] ?? null,
                'event_type' => $payload['eventName'] ?? null,
                'provider_transaction_id' => NGeniusPayload::orderReference($payload),
                'merchant_reference' => NGeniusPayload::merchantReference($payload),
            ],
        );
    }

    public function refund(RefundCommand $command): RefundProcessingResult
    {
        try {
            $order = new NGeniusOrder($this->client->retrieveOrder($command->providerTransactionId));
        } catch (Throwable $exception) {
            return RefundProcessingResult::retryableFailure('ngenius_order_unavailable', $exception->getMessage());
        }

        $href = $order->refundHref();

        if ($href === null) {
            return RefundProcessingResult::manualReviewRequired(
                'ngenius_refund_link_missing',
                'N-Genius did not expose a refundable capture for this order.'
            );
        }

        try {
            $response = $this->client->postAbsolute($href, [
                'amount' => [
                    'currencyCode' => strtoupper($command->currencyCode),
                    'value' => $command->amountMinor,
                ],
            ]);
        } catch (Throwable $exception) {
            return RefundProcessingResult::retryableFailure('ngenius_refund_failed', $exception->getMessage());
        }

        $refundId = $this->refundReference($response);

        if ($refundId === null) {
            return RefundProcessingResult::manualReviewRequired(
                'ngenius_refund_reference_missing',
                'N-Genius accepted the refund without returning a refund reference.'
            );
        }

        return RefundProcessingResult::succeeded($refundId, [
            'provider_transaction_id' => $command->providerTransactionId,
            'status' => $this->refundState($response),
        ]);
    }

    private function failureCode(NGeniusOrder $order): ?string
    {
        if (NGeniusPayload::status($order->state()) === PaymentTransactionStatus::Succeeded) {
            return null;
        }

        $state = $order->state();

        if ($state === '') {
            return null;
        }

        $resultCode = $order->resultCode();

        return $resultCode === null ? strtolower($state) : strtolower($state).':'.$resultCode;
    }

    private function refundReference(array $response): ?string
    {
        $refunds = $response['_embedded']['cnp:refund'] ?? [];

        if (! is_array($refunds)) {
            return null;
        }

        foreach (array_reverse($refunds) as $refund) {
            $href = $refund['_links']['self']['href'] ?? null;

            if (is_string($href) && $href !== '') {
                $segments = explode('/', rtrim($href, '/'));

                return end($segments) ?: null;
            }
        }

        return null;
    }

    private function refundState(array $response): ?string
    {
        $refunds = $response['_embedded']['cnp:refund'] ?? [];

        if (! is_array($refunds) || $refunds === []) {
            return null;
        }

        $last = end($refunds);

        return is_array($last) && isset($last['state']) ? (string) $last['state'] : null;
    }

    private function payerEmail(PaymentIntent $intent): string
    {
        $email = trim((string) ($intent->metadata['payer_email'] ?? ''));

        return $email !== '' ? $email : (string) config('auction.payments.providers.ngenius.fallback_email', 'payments@soom.test');
    }

    private function payerFirstName(PaymentIntent $intent): string
    {
        $parts = preg_split('/\s+/', trim((string) ($intent->metadata['payer_name'] ?? ''))) ?: [];
        $first = trim((string) ($parts[0] ?? ''));

        return $first !== '' ? $first : 'Soom';
    }

    private function payerLastName(PaymentIntent $intent): string
    {
        $parts = preg_split('/\s+/', trim((string) ($intent->metadata['payer_name'] ?? ''))) ?: [];

        if (count($parts) > 1) {
            $last = trim((string) end($parts));

            if ($last !== '') {
                return $last;
            }
        }

        return 'Customer';
    }

    private function supportedCurrencies(): array
    {
        $configured = (array) config('auction.payments.providers.ngenius.currencies', ['JOD']);

        return array_values(array_filter(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            $configured
        )));
    }
}
