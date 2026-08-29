<?php

declare(strict_types=1);

namespace Tests\Feature\Auction\Concerns;

use App\Domain\Auction\Enums\PaymentChannel;
use App\Domain\Auction\Enums\PaymentRail;
use App\Models\Auction\PaymentMethod;
use App\Services\Auction\Payments\Providers\NGenius\NGeniusPayload;
use App\Services\Auction\Payments\Providers\NGenius\NGeniusWebhookVerifier;
use App\Services\Auction\Payments\Providers\NGeniusPaymentProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

trait FakesNGenius
{
    protected string $ngeniusBaseUrl = 'https://api-gateway.sandbox.ngenius-payments.test';

    protected string $ngeniusSecret = 'abcdefghijklmnopqrstuvwxyz012345';

    protected function configureNGenius(array $overrides = []): void
    {
        config(array_replace([
            'services.ngenius.api_key' => 'test-api-key',
            'services.ngenius.outlet_reference' => 'outlet-1',
            'services.ngenius.base_url' => $this->ngeniusBaseUrl,
            'services.ngenius.webhook_secret' => $this->ngeniusSecret,
            'auction.payments.disabled_providers' => [],
            'auction.payments.providers.ngenius.sandbox' => true,
            'auction.payments.providers.ngenius.currencies' => ['JOD'],
        ], $overrides));

        Cache::flush();
    }

    protected function ngeniusMethod(array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_replace([
            'name' => 'N-Genius card',
            'code' => 'ngenius-'.Str::ulid(),
            'channel' => PaymentChannel::Online,
            'rail' => PaymentRail::Card,
            'provider_code' => NGeniusPaymentProvider::CODE,
            'is_sandbox' => true,
            'requires_manual_review' => false,
            'is_active' => true,
            'display_order' => 1,
        ], $overrides));
    }

    protected function ngeniusTokenUrl(): string
    {
        return $this->ngeniusBaseUrl.'/identity/auth/access-token';
    }

    protected function ngeniusOrdersUrl(): string
    {
        return $this->ngeniusBaseUrl.'/transactions/outlets/outlet-1/orders';
    }

    protected function ngeniusTokenResponse(): array
    {
        return ['access_token' => 'test-access-token', 'expires_in' => 300, 'token_type' => 'bearer'];
    }

    protected function ngeniusOrderResponse(
        string $reference,
        string $merchantReference,
        string $state = 'PURCHASED',
        int $amountMinor = 1_000,
        string $currency = 'JOD',
        ?string $resultCode = '00',
        bool $withCapture = true
    ): array {
        $capture = [
            '_links' => ['self' => ['href' => $this->ngeniusOrdersUrl().'/'.$reference.'/payments/p1/captures/c1']],
            'amount' => ['currencyCode' => $currency, 'value' => $amountMinor],
        ];

        return [
            '_id' => 'urn:order:'.$reference,
            'reference' => $reference,
            'action' => 'PURCHASE',
            'amount' => ['currencyCode' => $currency, 'value' => $amountMinor],
            'merchantOrderReference' => $merchantReference,
            'merchantDefinedData' => [NGeniusPayload::REFERENCE_KEY => $merchantReference],
            '_links' => ['payment' => ['href' => 'https://paypage.sandbox.ngenius-payments.test/?code='.$reference]],
            '_embedded' => [
                'payment' => [[
                    '_id' => 'urn:payment:p1',
                    'state' => $state,
                    'amount' => ['currencyCode' => $currency, 'value' => $amountMinor],
                    'authResponse' => ['resultCode' => $resultCode, 'success' => $state === 'PURCHASED'],
                    '_links' => $withCapture
                        ? ['cnp:refund' => ['href' => $this->ngeniusOrdersUrl().'/'.$reference.'/payments/p1/captures/c1/refund']]
                        : [],
                    '_embedded' => $withCapture ? ['cnp:capture' => [$capture]] : [],
                ]],
            ],
        ];
    }

    protected function ngeniusRefundResponse(string $refundId, string $state = 'SUCCESS'): array
    {
        return [
            '_embedded' => [
                'cnp:refund' => [[
                    'state' => $state,
                    '_links' => ['self' => ['href' => $this->ngeniusOrdersUrl().'/o1/payments/p1/captures/c1/refund/'.$refundId]],
                ]],
            ],
        ];
    }

    protected function ngeniusWebhook(string $orderReference, string $merchantReference, string $eventId, string $eventName = 'PURCHASED'): array
    {
        return [
            'outletId' => 'outlet-1',
            'eventId' => $eventId,
            'eventName' => $eventName,
            'order' => [
                'action' => 'PURCHASE',
                'reference' => $orderReference,
                'merchantOrderReference' => $merchantReference,
                'merchantDefinedData' => [NGeniusPayload::REFERENCE_KEY => $merchantReference],
                'amount' => ['currencyCode' => 'JOD', 'value' => 1_000],
                '_embedded' => ['payment' => [['state' => $eventName]]],
            ],
        ];
    }

    protected function postNGeniusWebhook(array $payload, ?string $secret = null, bool $encrypted = false)
    {
        $body = json_encode($payload);

        if ($encrypted) {
            $iv = random_bytes(16);
            $body = base64_encode($iv.openssl_encrypt($body, 'aes-256-cbc', $this->ngeniusSecret, OPENSSL_RAW_DATA, $iv));
        }

        return $this->call(
            'POST',
            '/api/webhooks/payments/ngenius',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_'.str_replace('-', '_', strtoupper(NGeniusWebhookVerifier::SECRET_HEADER)) => $secret ?? $this->ngeniusSecret,
            ],
            $body
        );
    }
}
