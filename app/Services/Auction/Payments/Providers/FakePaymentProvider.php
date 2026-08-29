<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers;

use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\ProviderAmountFormat;
use App\DTO\Auction\RefundProcessingResult;
use App\Services\Auction\Payments\CheckoutInstruction;
use App\Services\Auction\Payments\Contracts\PaymentProvider;
use App\Services\Auction\Payments\PaymentIntent;
use App\Services\Auction\Payments\ProviderCapabilities;
use App\Services\Auction\Payments\ProviderEvent;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use App\Services\Auction\Payments\RefundCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

final class FakePaymentProvider implements PaymentProvider
{
    public const CODE = 'fake';

    private array $charges = [];

    private array $refunds = [];

    private bool $checkoutFails = false;

    public function code(): string
    {
        return self::CODE;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            amountFormat: ProviderAmountFormat::DecimalString,
            supportedCurrencies: ['JOD', 'USD'],
            supportsRefund: true,
            supportsPartialRefund: true,
            supportsInquiry: true,
            supportsWebhook: true,
        );
    }

    public function createCheckout(PaymentIntent $intent): CheckoutInstruction
    {
        if ($this->checkoutFails) {
            throw new RuntimeException('Fake provider checkout unavailable.');
        }

        $reference = 'fake_'.strtolower((string) Str::ulid());

        $this->charges[$reference] = [
            'status' => PaymentTransactionStatus::Pending,
            'amount_minor' => $intent->amountMinor,
            'currency_code' => $intent->currencyCode,
            'merchant_reference' => $intent->merchantReference,
            'failure_code' => null,
            'provider_fee_minor' => null,
            'settlement_reference' => null,
        ];

        return new CheckoutInstruction(
            providerTransactionId: $reference,
            type: 'redirect',
            redirectUrl: rtrim((string) config('auction.payments.providers.fake.checkout_url', 'https://fake-checkout.test'), '/').'/'.$reference,
            reference: $reference,
            expiresInSeconds: (int) config('auction.payments.intent_ttl_seconds', 1800),
        );
    }

    public function fetchStatus(string $providerTransactionId): ProviderPaymentStatus
    {
        $charge = $this->charges[$providerTransactionId] ?? null;

        if ($charge === null) {
            throw new RuntimeException('Fake provider transaction not found.');
        }

        return new ProviderPaymentStatus(
            status: $charge['status'],
            providerTransactionId: $providerTransactionId,
            amountMinor: $charge['amount_minor'],
            currencyCode: $charge['currency_code'],
            failureCode: $charge['failure_code'],
            providerFeeMinor: $charge['provider_fee_minor'],
            settlementReference: $charge['settlement_reference'],
            merchantReference: $charge['merchant_reference'],
            payload: [
                'status' => $charge['status']->value,
                'merchant_reference' => $charge['merchant_reference'],
            ],
        );
    }

    public function parseEvent(Request $request): ProviderEvent
    {
        $payload = (array) $request->json()->all();
        $signature = (string) $request->header('X-Fake-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $this->webhookSecret());

        return new ProviderEvent(
            eventId: (string) ($payload['event_id'] ?? ''),
            eventType: (string) ($payload['event_type'] ?? ''),
            providerTransactionId: (string) ($payload['provider_transaction_id'] ?? ''),
            signatureVerified: $signature !== '' && hash_equals($expected, $signature),
            merchantReference: isset($payload['merchant_reference']) ? (string) $payload['merchant_reference'] : null,
            payload: $payload,
        );
    }

    public function refund(RefundCommand $command): RefundProcessingResult
    {
        $scripted = $this->refunds[$command->providerTransactionId] ?? 'succeeded';

        if ($scripted === 'retryable') {
            return RefundProcessingResult::retryableFailure('fake_refund_unavailable', 'Fake provider refund is temporarily unavailable.');
        }

        if ($scripted === 'failed') {
            return RefundProcessingResult::nonRetryableFailure('fake_refund_rejected', 'Fake provider rejected the refund.');
        }

        return RefundProcessingResult::succeeded(
            'fake_refund_'.strtolower((string) Str::ulid()),
            ['provider_transaction_id' => $command->providerTransactionId]
        );
    }

    public function signPayload(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->webhookSecret());
    }

    public function markSucceeded(
        string $providerTransactionId,
        ?int $amountMinor = null,
        ?string $currencyCode = null,
        ?int $providerFeeMinor = null,
        ?string $settlementReference = null
    ): void {
        $this->updateCharge($providerTransactionId, [
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amountMinor ?? $this->charges[$providerTransactionId]['amount_minor'] ?? null,
            'currency_code' => $currencyCode ?? $this->charges[$providerTransactionId]['currency_code'] ?? null,
            'provider_fee_minor' => $providerFeeMinor,
            'settlement_reference' => $settlementReference,
        ]);
    }

    public function markFailed(string $providerTransactionId, string $failureCode = 'declined'): void
    {
        $this->updateCharge($providerTransactionId, [
            'status' => PaymentTransactionStatus::Failed,
            'failure_code' => $failureCode,
        ]);
    }

    public function markCancelled(string $providerTransactionId): void
    {
        $this->updateCharge($providerTransactionId, ['status' => PaymentTransactionStatus::Cancelled]);
    }

    public function markExpired(string $providerTransactionId): void
    {
        $this->updateCharge($providerTransactionId, ['status' => PaymentTransactionStatus::Expired]);
    }

    public function failCheckouts(bool $shouldFail = true): void
    {
        $this->checkoutFails = $shouldFail;
    }

    public function scriptRefund(string $providerTransactionId, string $outcome): void
    {
        $this->refunds[$providerTransactionId] = $outcome;
    }

    public function reset(): void
    {
        $this->charges = [];
        $this->refunds = [];
        $this->checkoutFails = false;
    }

    private function updateCharge(string $providerTransactionId, array $attributes): void
    {
        $this->charges[$providerTransactionId] = array_replace(
            $this->charges[$providerTransactionId] ?? [
                'status' => PaymentTransactionStatus::Pending,
                'amount_minor' => null,
                'currency_code' => null,
                'merchant_reference' => null,
                'failure_code' => null,
                'provider_fee_minor' => null,
                'settlement_reference' => null,
            ],
            $attributes
        );
    }

    private function webhookSecret(): string
    {
        return (string) config('services.fake.webhook_secret', 'fake-webhook-secret');
    }
}
