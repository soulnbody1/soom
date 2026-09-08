<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers;

use App\Domain\Auction\Enums\BillRejectionReason;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\ProviderAmountFormat;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\Auction\ValueObjects\BillReference;
use App\DTO\Auction\RefundProcessingResult;
use App\Services\Auction\Payments\Bills\BillQuery;
use App\Services\Auction\Payments\Bills\BillReferenceAllocator;
use App\Services\Auction\Payments\Bills\BillResolution;
use App\Services\Auction\Payments\Bills\PresentableBill;
use App\Services\Auction\Payments\CheckoutInstruction;
use App\Services\Auction\Payments\Contracts\DerivesStatusFromEvent;
use App\Services\Auction\Payments\Contracts\PaymentProvider;
use App\Services\Auction\Payments\Contracts\PresentsBills;
use App\Services\Auction\Payments\Contracts\RendersProviderResponse;
use App\Services\Auction\Payments\Contracts\VerifiesProviderConnection;
use App\Services\Auction\Payments\PaymentIntent;
use App\Services\Auction\Payments\ProviderAmountFormatter;
use App\Services\Auction\Payments\ProviderCapabilities;
use App\Services\Auction\Payments\ProviderEvent;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use App\Services\Auction\Payments\Providers\EFawateercom\EFawateercomSettings;
use App\Services\Auction\Payments\Providers\EFawateercom\MfepMessage;
use App\Services\Auction\Payments\Providers\EFawateercom\MfepResult;
use App\Services\Auction\Payments\RefundCommand;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EFawateercomPaymentProvider implements DerivesStatusFromEvent, PaymentProvider, PresentsBills, RendersProviderResponse, VerifiesProviderConnection
{
    public const CODE = 'efawateercom';

    private const BILL_PULL_REQUEST = 'BILPULRQ';

    private const BILL_PULL_RESPONSE = 'BILPULRS';

    private const NOTIFICATION_REQUEST = 'BLRPMTNTFRQ';

    private const NOTIFICATION_RESPONSE = 'BLRPMTNTFRS';

    private const CURRENCY = 'JOD';

    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s';

    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly BillReferenceAllocator $billReferences,
        private readonly ProviderAmountFormatter $amounts,
        private readonly EFawateercomSettings $settings,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            amountFormat: ProviderAmountFormat::DecimalString,
            supportedCurrencies: [self::CURRENCY],
            supportsRefund: false,
            supportsPartialRefund: false,
            supportsInquiry: false,
            supportsWebhook: true,
        );
    }

    public function verifyConnection(): void
    {
        if ($this->settings->billerCode() === '') {
            throw new RuntimeException('The biller code is not configured.');
        }

        if ($this->settings->username() === '' || $this->settings->password() === '') {
            throw new RuntimeException('Inbound credentials are not configured.');
        }

        $method = $this->settings->method();

        if ($method === null || $this->settings->serviceTypes($method) === []) {
            throw new RuntimeException('No service types are mapped to payment purposes.');
        }
    }

    public function createCheckout(PaymentIntent $intent): CheckoutInstruction
    {
        $claim = $this->billReferences->allocate(self::CODE);

        return new CheckoutInstruction(
            providerTransactionId: $claim->value,
            type: 'bill',
            redirectUrl: null,
            reference: $claim->value,
            expiresInSeconds: $this->settings->billTtlSeconds(),
            details: [
                'biller_code' => $this->settings->billerCode(),
                'claim_reference' => $claim->value,
                'principal' => $this->decimal($intent->amountMinor, $intent->currencyCode),
                'customer_fee' => $this->decimal($intent->customerFeeMinor, $intent->currencyCode),
                'amount' => $this->decimal($intent->payableAmountMinor(), $intent->currencyCode),
                'currency' => strtoupper($intent->currencyCode),
                'payable_until' => $intent->payableUntil?->toIso8601String(),
            ],
        );
    }

    public function fetchStatus(string $providerTransactionId): ProviderPaymentStatus
    {
        throw new RuntimeException('This payment rail exposes no status inquiry.');
    }

    public function parseBillQuery(Request $request): BillQuery
    {
        $this->assertAuthenticated($request);

        $message = MfepMessage::fromRequest($request);

        if ($message->requestType() !== self::BILL_PULL_REQUEST) {
            throw AuctionException::domain('payment_bill_query_invalid', [], 400);
        }

        $account = $message->bodyArray('AcctInfo');
        $billNo = MfepMessage::string($account, 'BillNo');
        $billingNo = MfepMessage::string($account, 'BillingNo');
        $reference = BillReference::tryFrom($this->claim($billNo, $billingNo));

        if ($reference === null) {
            throw AuctionException::domain('payment_bill_query_invalid', [], 400);
        }

        $serviceType = $message->bodyString('ServiceType');
        $purpose = $this->purposeFor($serviceType);

        if ($purpose === null && $serviceType !== null && $serviceType !== '') {
            throw AuctionException::domain('payment_bill_query_invalid', [], 400);
        }

        return new BillQuery(
            providerCode: self::CODE,
            billingReference: null,
            billReference: $reference,
            purpose: $purpose,
            receivedAt: CarbonImmutable::now(),
            requestId: $message->guid(),
        );
    }

    public function renderBills(BillQuery $query, BillResolution $resolution): Response
    {
        $claim = (string) $query->billReference?->value;
        $records = $resolution->bills === []
            ? [$this->rejectedRecord($claim, $resolution->rejection)]
            : array_map(fn (PresentableBill $bill): array => $this->presentedRecord($bill), $resolution->bills);

        return $this->envelope(self::BILL_PULL_RESPONSE, $this->requestGuid($query), MfepResult::success(), [
            'RecCount' => count($records),
            'BillRec' => $records,
        ]);
    }

    public function renderBillQueryFailure(Request $request, Throwable $error): Response
    {
        $status = $this->statusFor($request, $error);
        $key = $status === 401 ? 'unauthenticated' : 'invalid_request';

        return $this->envelope(
            self::BILL_PULL_RESPONSE,
            MfepMessage::fromRequest($request)->guid(),
            MfepResult::error($this->settings->requestErrorCode($key), $this->reason($key)),
            ['RecCount' => 0, 'BillRec' => []],
            $status
        );
    }

    public function parseEvent(Request $request): ProviderEvent
    {
        if (! $this->isAuthenticated($request)) {
            return new ProviderEvent(
                eventId: '',
                eventType: self::NOTIFICATION_REQUEST,
                providerTransactionId: '',
                signatureVerified: false,
                merchantReference: null,
                payload: [],
            );
        }

        $message = MfepMessage::fromRequest($request);
        $transfer = MfepMessage::nested($message->body, 'Transactions', 'TrxInf');
        $account = MfepMessage::nested($transfer, 'AcctInfo');
        $service = MfepMessage::nested($transfer, 'ServiceTypeDetails');
        $serviceType = MfepMessage::string($service, 'ServiceType');

        if ($serviceType !== null && $serviceType !== '' && $this->purposeFor($serviceType) === null) {
            throw AuctionException::domain('payment_webhook_invalid', [], 400);
        }

        $billNo = MfepMessage::string($account, 'BillNo');
        $billingNo = MfepMessage::string($account, 'BillingNo');

        return new ProviderEvent(
            eventId: (string) MfepMessage::string($transfer, 'JOEBPPSTrx'),
            eventType: self::NOTIFICATION_REQUEST,
            providerTransactionId: $this->claim($billNo, $billingNo),
            signatureVerified: true,
            merchantReference: null,
            payload: [
                'event_id' => MfepMessage::string($transfer, 'JOEBPPSTrx'),
                'event_type' => self::NOTIFICATION_REQUEST,
                'provider_transaction_id' => $this->claim($billNo, $billingNo),
                'amount' => MfepMessage::string($transfer, 'PaidAmt'),
                'due_amount' => MfepMessage::string($transfer, 'DueAmt'),
                'currency' => MfepMessage::string($transfer, 'Currency'),
                'provider_fee' => MfepMessage::string($transfer, 'FeesAmt'),
                'fee_on_biller' => is_bool($transfer['FeesOnBiller'] ?? null) ? $transfer['FeesOnBiller'] : null,
                'settlement_reference' => MfepMessage::string($transfer, 'BankTrxId'),
                'bank_code' => MfepMessage::string($transfer, 'BankCode'),
                'status' => MfepMessage::string($transfer, 'PmtStatus'),
                'timestamp' => MfepMessage::string($transfer, 'ProcessDate'),
                'settled_at' => MfepMessage::string($transfer, 'ProcessDate'),
                'statement_date' => MfepMessage::string($transfer, 'StmtDate'),
                'payment_channel' => MfepMessage::string($transfer, 'AccessChannel'),
                'payment_method' => MfepMessage::string($transfer, 'PaymentMethod'),
                'payment_type' => MfepMessage::string($transfer, 'PaymentType'),
                'service_code' => $serviceType,
            ],
        );
    }

    public function statusFromEvent(ProviderEvent $event): ProviderPaymentStatus
    {
        $payload = $event->payload;
        $currency = strtoupper((string) ($payload['currency'] ?? '')) ?: self::CURRENCY;
        $paid = $payload['amount'] ?? null;
        $fees = $payload['provider_fee'] ?? null;
        $processedAt = $payload['settled_at'] ?? null;
        $bankReference = $payload['settlement_reference'] ?? null;

        return new ProviderPaymentStatus(
            status: PaymentTransactionStatus::Succeeded,
            providerTransactionId: $event->providerTransactionId,
            amountMinor: $paid === null ? null : $this->amounts->toMinor((string) $paid, $currency, $this->capabilities()),
            currencyCode: $currency,
            failureCode: null,
            providerFeeMinor: $fees === null ? null : $this->amounts->toMinor((string) $fees, $currency, $this->capabilities()),
            settlementReference: $bankReference === null ? null : (string) $bankReference,
            settledAt: $processedAt === null ? null : CarbonImmutable::parse((string) $processedAt),
            providerEventId: $event->eventId,
            merchantReference: null,
            payload: $payload,
        );
    }

    public function renderEventResponse(Request $request, string $outcome): Response
    {
        $acknowledged = in_array($outcome, ['processed', 'duplicate'], true);

        return $this->notificationEnvelope(
            $request,
            $acknowledged
                ? MfepResult::success()
                : MfepResult::error($this->settings->requestErrorCode($outcome), $this->reason($outcome)),
            $acknowledged ? 200 : 422
        );
    }

    public function renderEventFailure(Request $request, Throwable $error): Response
    {
        $status = $this->statusFor($request, $error);
        $key = $status === 401 ? 'unauthenticated' : 'invalid_request';

        return $this->notificationEnvelope(
            $request,
            MfepResult::error($this->settings->requestErrorCode($key), $this->reason($key)),
            $status
        );
    }

    public function refund(RefundCommand $command): RefundProcessingResult
    {
        return RefundProcessingResult::manualReviewRequired(
            'bill_rail_refund_unsupported',
            'This payment rail has no reversal API; the refund must be settled outside it.',
            ['provider_transaction_id' => $command->providerTransactionId]
        );
    }

    private function presentedRecord(PresentableBill $bill): array
    {
        $payable = $this->decimal($bill->payableMinor(), $bill->currencyCode);

        return [
            'Result' => MfepResult::success()->toArray(),
            'AcctInfo' => [
                'BillingNo' => $bill->billReference->value,
                'BillNo' => $bill->billReference->value,
            ],
            'BillStatus' => $this->settings->billStatus(),
            'DueAmount' => $payable,
            'IssueDate' => $bill->issuedAt->format(self::TIMESTAMP_FORMAT),
            'DueDate' => ($bill->payableUntil ?? $bill->issuedAt)->format(self::TIMESTAMP_FORMAT),
            'ExpiryDate' => $bill->payableUntil?->format(self::TIMESTAMP_FORMAT),
            'ServiceType' => $this->serviceTypeFor($bill->purpose),
            'BillType' => $this->settings->billType(),
            'PmtConst' => [
                'AllowPart' => $bill->allowsPartialPayment(),
                'Lower' => $payable,
                'Upper' => $payable,
            ],
            'AdditionalInfo' => [
                'CustName' => $bill->payerDisplayName,
                'FreeText' => $bill->auctionTitle,
            ],
        ];
    }

    private function rejectedRecord(string $claim, ?BillRejectionReason $reason): array
    {
        $reason ??= BillRejectionReason::BillNotFound;
        $now = CarbonImmutable::now();

        return [
            'Result' => MfepResult::error(
                $this->settings->rejectionCode($reason),
                $this->reason($reason->value)
            )->toArray(),
            'AcctInfo' => [
                'BillingNo' => $claim,
                'BillNo' => $claim,
            ],
            'BillStatus' => $this->settings->billStatus(),
            'DueAmount' => $this->decimal(0, self::CURRENCY),
            'IssueDate' => $now->format(self::TIMESTAMP_FORMAT),
            'DueDate' => $now->format(self::TIMESTAMP_FORMAT),
            'ServiceType' => '',
            'BillType' => $this->settings->billType(),
        ];
    }

    private function notificationEnvelope(Request $request, MfepResult $result, int $status): Response
    {
        $message = MfepMessage::fromRequest($request);
        $transfer = MfepMessage::nested($message->body, 'Transactions', 'TrxInf');
        $statementDate = MfepMessage::string($transfer, 'StmtDate');

        return $this->envelope(self::NOTIFICATION_RESPONSE, $message->guid(), $result, [
            'Transactions' => [
                'TrxInf' => [
                    'JOEBPPSTrx' => (string) MfepMessage::string($transfer, 'JOEBPPSTrx'),
                    'ProcessDate' => (string) MfepMessage::string($transfer, 'ProcessDate'),
                    'STMTDate' => $statementDate ?? CarbonImmutable::now()->format(self::DATE_FORMAT),
                    'Result' => $result->toArray(),
                ],
            ],
        ], $status);
    }

    private function envelope(string $responseType, string $guid, MfepResult $result, array $body, int $status = 200): Response
    {
        return new JsonResponse([
            'MFEP' => [
                'MsgHeader' => [
                    'TmStp' => CarbonImmutable::now()->format(self::TIMESTAMP_FORMAT),
                    'GUID' => $guid,
                    'TrsInf' => [
                        'SdrCode' => $this->settings->billerCode(),
                        'ResTyp' => $responseType,
                    ],
                    'Result' => $result->toArray(),
                ],
                'MsgBody' => $body,
            ],
        ], $status);
    }

    private function claim(?string $billNo, ?string $billingNo): string
    {
        if ($billNo !== null && $billNo !== '') {
            return $billNo;
        }

        return (string) $billingNo;
    }

    private function requestGuid(BillQuery $query): string
    {
        return (string) ($query->requestId ?? '');
    }

    private function serviceTypeFor(PaymentPurpose $purpose): string
    {
        $method = $this->settings->method();

        return $method === null ? '' : (string) $this->settings->serviceTypeFor($method, $purpose);
    }

    private function purposeFor(?string $serviceType): ?PaymentPurpose
    {
        if ($serviceType === null || $serviceType === '') {
            return null;
        }

        $method = $this->settings->method();

        return $method === null ? null : $this->settings->purposeForServiceType($method, $serviceType);
    }

    private function statusFor(Request $request, Throwable $error): int
    {
        if (! $this->isAuthenticated($request)) {
            return 401;
        }

        return method_exists($error, 'getStatusCode') ? (int) $error->getStatusCode() : 500;
    }

    private function assertAuthenticated(Request $request): void
    {
        if (! $this->isAuthenticated($request)) {
            throw new RuntimeException('Unauthenticated request.');
        }
    }

    private function isAuthenticated(Request $request): bool
    {
        $username = $this->settings->username();
        $password = $this->settings->password();

        if ($username === '' || $password === '') {
            return false;
        }

        return hash_equals($username, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());
    }

    private function decimal(int $amountMinor, string $currencyCode): string
    {
        return $this->amounts->format($amountMinor, $currencyCode, $this->capabilities());
    }

    private function reason(string $key): string
    {
        return (string) __('auction.bill_results.'.$key);
    }
}
