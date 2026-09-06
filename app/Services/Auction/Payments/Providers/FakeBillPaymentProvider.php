<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers;

use App\Domain\Auction\Enums\BillRejectionReason;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\ProviderAmountFormat;
use App\Domain\Auction\ValueObjects\BillingReference;
use App\Domain\Auction\ValueObjects\BillReference;
use App\DTO\Auction\RefundProcessingResult;
use App\Services\Auction\Payments\Bills\BillingReferenceAllocator;
use App\Services\Auction\Payments\Bills\BillQuery;
use App\Services\Auction\Payments\Bills\BillReferenceAllocator;
use App\Services\Auction\Payments\Bills\BillResolution;
use App\Services\Auction\Payments\Bills\PresentableBill;
use App\Services\Auction\Payments\CheckoutInstruction;
use App\Services\Auction\Payments\Contracts\DerivesStatusFromEvent;
use App\Services\Auction\Payments\Contracts\PaymentProvider;
use App\Services\Auction\Payments\Contracts\PresentsBills;
use App\Services\Auction\Payments\Contracts\RendersProviderResponse;
use App\Services\Auction\Payments\PaymentIntent;
use App\Services\Auction\Payments\ProviderAmountFormatter;
use App\Services\Auction\Payments\ProviderCapabilities;
use App\Services\Auction\Payments\ProviderEvent;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use App\Services\Auction\Payments\RefundCommand;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A bill-rail provider used to exercise the presentment layer end to end.
 *
 * It stands in for the shape every bill scheme shares: the payer is not
 * redirected anywhere, the platform is asked what is owed, and a signed inbound
 * event is the only statement that money moved. Its wire format is its own — no
 * real scheme's field names appear here or anywhere in the core.
 */
final class FakeBillPaymentProvider implements DerivesStatusFromEvent, PaymentProvider, PresentsBills, RendersProviderResponse
{
    public const CODE = 'fake_bill';

    public const SIGNATURE_HEADER = 'X-Fake-Bill-Signature';

    public function __construct(
        private readonly BillingReferenceAllocator $billingReferences,
        private readonly BillReferenceAllocator $billReferences,
        private readonly ProviderAmountFormatter $amounts,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            amountFormat: ProviderAmountFormat::DecimalString,
            supportedCurrencies: ['JOD'],
            supportsRefund: false,
            supportsPartialRefund: false,
            supportsInquiry: false,
            supportsWebhook: true,
        );
    }

    public function createCheckout(PaymentIntent $intent): CheckoutInstruction
    {
        if ($intent->payerId === null) {
            throw new RuntimeException('A bill cannot be raised without a payer.');
        }

        $billingReference = $this->billingReferences->forUser($intent->payerId, self::CODE);
        $billReference = $this->billReferences->allocate(self::CODE);

        return new CheckoutInstruction(
            providerTransactionId: $billReference->value,
            type: 'bill',
            redirectUrl: null,
            reference: $billReference->value,
            expiresInSeconds: $this->ttlSeconds($intent),
            details: [
                'billing_reference' => $billingReference->value,
                'bill_reference' => $billReference->value,
                'amount' => $this->amounts->format($intent->amountMinor, $intent->currencyCode, $this->capabilities()),
                'currency' => strtoupper($intent->currencyCode),
                'payable_until' => $intent->payableUntil?->toIso8601String(),
            ],
        );
    }

    /**
     * A bill scheme presents; it does not answer questions about past payments.
     */
    public function fetchStatus(string $providerTransactionId): ProviderPaymentStatus
    {
        throw new RuntimeException('This provider exposes no status inquiry.');
    }

    public function parseEvent(Request $request): ProviderEvent
    {
        $verified = $this->verify($request);
        $payload = $verified ? (array) $request->json()->all() : [];

        return new ProviderEvent(
            eventId: (string) ($payload['event_id'] ?? ''),
            eventType: (string) ($payload['event_type'] ?? 'bill.paid'),
            providerTransactionId: (string) ($payload['bill_reference'] ?? ''),
            signatureVerified: $verified,
            merchantReference: null,
            payload: [
                'event_id' => $payload['event_id'] ?? null,
                'event_type' => $payload['event_type'] ?? null,
                'provider_transaction_id' => $payload['bill_reference'] ?? null,
                'amount' => $payload['paid_amount'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'settlement_reference' => $payload['settlement_reference'] ?? null,
                'fee_minor' => $payload['fee_minor'] ?? null,
                'settled_at' => $payload['settled_at'] ?? null,
            ],
        );
    }

    public function statusFromEvent(ProviderEvent $event): ProviderPaymentStatus
    {
        $payload = $event->payload;
        $currency = strtoupper((string) ($payload['currency'] ?? 'JOD'));
        $amount = $payload['amount'] ?? null;

        return new ProviderPaymentStatus(
            status: PaymentTransactionStatus::Succeeded,
            providerTransactionId: $event->providerTransactionId,
            amountMinor: $amount === null ? null : $this->amounts->toMinor((string) $amount, $currency, $this->capabilities()),
            currencyCode: $currency,
            failureCode: null,
            providerFeeMinor: isset($payload['fee_minor']) ? (int) $payload['fee_minor'] : null,
            settlementReference: isset($payload['settlement_reference']) ? (string) $payload['settlement_reference'] : null,
            settledAt: isset($payload['settled_at']) ? CarbonImmutable::parse((string) $payload['settled_at']) : null,
            merchantReference: null,
            payload: $payload,
        );
    }

    /**
     * No reversal exists on this rail, so refunds leave the platform for a human.
     */
    public function refund(RefundCommand $command): RefundProcessingResult
    {
        return RefundProcessingResult::manualReviewRequired(
            'bill_rail_refund_unsupported',
            'This payment rail has no reversal API; the refund must be settled manually.',
            ['provider_transaction_id' => $command->providerTransactionId]
        );
    }

    public function parseBillQuery(Request $request): BillQuery
    {
        if (! $this->verify($request)) {
            throw new RuntimeException('Unauthenticated bill query.');
        }

        $payload = (array) $request->json()->all();
        $billingReference = BillingReference::tryFrom($payload['billing_reference'] ?? null);
        $billReference = BillReference::tryFrom($payload['bill_reference'] ?? null);

        if (! $billingReference && ! $billReference) {
            throw new RuntimeException('A bill query must carry at least one reference.');
        }

        return new BillQuery(
            providerCode: self::CODE,
            billingReference: $billingReference,
            billReference: $billReference,
            purpose: $this->purpose($payload['purpose'] ?? null),
            receivedAt: CarbonImmutable::now(),
        );
    }

    public function renderBills(BillQuery $query, BillResolution $resolution): Response
    {
        return new JsonResponse([
            'billing_reference' => $query->billingReference?->value,
            'count' => $resolution->count(),
            'rejection' => $this->rejectionCode($resolution->rejection),
            'bills' => array_map(fn (PresentableBill $bill): array => [
                'bill_reference' => $bill->billReference->value,
                'purpose' => $bill->purpose->value,
                'principal' => $this->amounts->format($bill->principalMinor, $bill->currencyCode, $this->capabilities()),
                'fee' => $this->amounts->format($bill->customerFeeMinor, $bill->currencyCode, $this->capabilities()),
                'amount' => $this->amounts->format($bill->payableMinor(), $bill->currencyCode, $this->capabilities()),
                'currency' => $bill->currencyCode,
                'allows_partial' => $bill->allowsPartialPayment(),
                'minimum' => $this->amounts->format($bill->minimumPayableMinor(), $bill->currencyCode, $this->capabilities()),
                'maximum' => $this->amounts->format($bill->maximumPayableMinor(), $bill->currencyCode, $this->capabilities()),
                'issued_at' => $bill->issuedAt->toIso8601String(),
                'payable_until' => $bill->payableUntil?->toIso8601String(),
                'payer_name' => $bill->payerDisplayName,
                'description' => $bill->auctionTitle,
            ], $resolution->bills),
        ]);
    }

    public function renderBillQueryFailure(Request $request, Throwable $error): Response
    {
        return new JsonResponse([
            'billing_reference' => null,
            'count' => 0,
            'rejection' => 'query_rejected',
            'bills' => [],
        ], 400);
    }

    public function renderEventResponse(Request $request, string $outcome): Response
    {
        return new JsonResponse(['acknowledged' => true, 'outcome' => $outcome]);
    }

    public function renderEventFailure(Request $request, Throwable $error): Response
    {
        $status = method_exists($error, 'getStatusCode') ? (int) $error->getStatusCode() : 500;

        return new JsonResponse(['acknowledged' => false, 'outcome' => 'rejected'], $status);
    }

    public function signPayload(array $payload): string
    {
        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->secret()
        );
    }

    private function verify(Request $request): bool
    {
        $secret = $this->secret();
        $presented = trim((string) $request->header(self::SIGNATURE_HEADER, ''));

        if ($secret === '' || $presented === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $presented);
    }

    private function purpose(mixed $value): ?PaymentPurpose
    {
        return is_string($value) && $value !== '' ? PaymentPurpose::tryFrom($value) : null;
    }

    private function rejectionCode(?BillRejectionReason $reason): ?string
    {
        return $reason?->value;
    }

    /**
     * A bill must never outlive the obligation it settles.
     */
    private function ttlSeconds(PaymentIntent $intent): int
    {
        $configured = max(60, (int) config('auction.payments.providers.'.self::CODE.'.bill_ttl_seconds', 86400));

        if ($intent->payableUntil === null) {
            return $configured;
        }

        $remaining = CarbonImmutable::now()->diffInSeconds($intent->payableUntil, false);

        return $remaining > 0 ? min($configured, (int) $remaining) : 1;
    }

    private function secret(): string
    {
        return (string) config('services.'.self::CODE.'.webhook_secret', '');
    }
}
