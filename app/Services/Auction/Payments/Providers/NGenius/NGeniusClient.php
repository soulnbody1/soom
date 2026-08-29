<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers\NGenius;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NGeniusClient
{
    private const PAYMENT_MEDIA_TYPE = 'application/vnd.ni-payment.v2+json';

    private const IDENTITY_MEDIA_TYPE = 'application/vnd.ni-identity.v1+json';

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->outletReference() !== '' && $this->baseUrl() !== '';
    }

    public function accessToken(bool $fresh = false): string
    {
        $cacheKey = 'ngenius:access-token:'.sha1($this->baseUrl().'|'.$this->apiKey());

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Basic '.$this->apiKey(),
            'Content-Type' => self::IDENTITY_MEDIA_TYPE,
            'Accept' => self::IDENTITY_MEDIA_TYPE,
        ])
            ->timeout($this->timeout())
            ->post($this->baseUrl().'/identity/auth/access-token', ['realmName' => 'ni']);

        if (! $response->successful()) {
            throw new NGeniusRequestException(
                $response->status(),
                'authentication',
                $this->baseUrl().'/identity/auth/access-token',
                (array) $response->json(),
                ['realmName' => 'ni']
            );
        }

        $token = (string) $response->json('access_token', '');

        if ($token === '') {
            throw new RuntimeException('N-Genius authentication returned no access token.');
        }

        $ttl = max(30, ((int) $response->json('expires_in', 300)) - 30);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    public function createOrder(array $payload): array
    {
        return $this->send(
            fn (PendingRequest $request): Response => $request->post($this->ordersUrl(), $payload),
            'create order',
            $this->ordersUrl(),
            $payload
        );
    }

    public function retrieveOrder(string $orderReference): array
    {
        $url = $this->ordersUrl().'/'.rawurlencode($orderReference);

        return $this->send(
            fn (PendingRequest $request): Response => $request->get($url),
            'retrieve order',
            $url,
            []
        );
    }

    public function postAbsolute(string $url, array $payload): array
    {
        return $this->send(
            fn (PendingRequest $request): Response => $request->post($url, $payload),
            'refund',
            $url,
            $payload
        );
    }

    public function outletReference(): string
    {
        return trim((string) config('services.ngenius.outlet_reference', ''));
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string) config('services.ngenius.base_url', '')), '/');
    }

    private function ordersUrl(): string
    {
        return $this->baseUrl().'/transactions/outlets/'.rawurlencode($this->outletReference()).'/orders';
    }

    private function send(callable $call, string $operation, string $url, array $payload): array
    {
        $response = $call($this->request($this->accessToken()));

        if ($response->status() === 401) {
            $response = $call($this->request($this->accessToken(true)));
        }

        if (! $response->successful()) {
            throw new NGeniusRequestException(
                $response->status(),
                $operation,
                $url,
                $this->decode($response),
                $payload
            );
        }

        return (array) $response->json();
    }

    private function decode(Response $response): array
    {
        $decoded = $response->json();

        if (is_array($decoded)) {
            return $decoded;
        }

        $raw = trim($response->body());

        return $raw === '' ? [] : ['raw' => mb_substr($raw, 0, 2000)];
    }

    private function request(string $token): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => self::PAYMENT_MEDIA_TYPE,
            'Accept' => self::PAYMENT_MEDIA_TYPE,
        ])->timeout($this->timeout());
    }

    private function apiKey(): string
    {
        return trim((string) config('services.ngenius.api_key', ''));
    }

    private function timeout(): int
    {
        return max(5, (int) config('auction.payments.providers.ngenius.timeout_seconds', 20));
    }
}
