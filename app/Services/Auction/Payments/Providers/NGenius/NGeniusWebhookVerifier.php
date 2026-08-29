<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers\NGenius;

use Illuminate\Http\Request;

final class NGeniusWebhookVerifier
{
    public const SECRET_HEADER = 'X-Webhook-Secret';

    private const CIPHER = 'aes-256-cbc';

    private const IV_LENGTH = 16;

    public function verify(Request $request): bool
    {
        $secret = $this->secret();

        if ($secret === '') {
            return false;
        }

        $presented = trim((string) $request->header(self::SECRET_HEADER, ''));

        return $presented !== '' && hash_equals($secret, $presented);
    }

    public function payload(Request $request): array
    {
        $body = (string) $request->getContent();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $plain = $this->decrypt($body);

        if ($plain === null) {
            return [];
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function decrypt(string $body): ?string
    {
        $secret = $this->secret();

        if (strlen($secret) !== 32) {
            return null;
        }

        $raw = base64_decode(trim($body), true);

        if ($raw === false || strlen($raw) <= self::IV_LENGTH) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, self::IV_LENGTH),
            self::CIPHER,
            $secret,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LENGTH)
        );

        return $plain === false ? null : $plain;
    }

    private function secret(): string
    {
        return trim((string) config('services.ngenius.webhook_secret', ''));
    }
}
