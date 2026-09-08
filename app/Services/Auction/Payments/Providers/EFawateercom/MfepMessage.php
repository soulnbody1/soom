<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers\EFawateercom;

use Illuminate\Http\Request;

final readonly class MfepMessage
{
    private function __construct(
        public array $header,
        public array $body,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $payload = (array) $request->json()->all();
        $envelope = is_array($payload['MFEP'] ?? null) ? $payload['MFEP'] : [];

        return new self(
            header: is_array($envelope['MsgHeader'] ?? null) ? $envelope['MsgHeader'] : [],
            body: is_array($envelope['MsgBody'] ?? null) ? $envelope['MsgBody'] : [],
        );
    }

    public function guid(): string
    {
        return (string) ($this->header['GUID'] ?? '');
    }

    public function requestType(): string
    {
        $transfer = is_array($this->header['TrsInf'] ?? null) ? $this->header['TrsInf'] : [];

        return (string) ($transfer['ReqTyp'] ?? '');
    }

    public function bodyString(string $key): ?string
    {
        $value = $this->body[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : null;
    }

    public function bodyArray(string $key): array
    {
        return is_array($this->body[$key] ?? null) ? $this->body[$key] : [];
    }

    public static function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : null;
    }

    public static function nested(array $source, string ...$keys): array
    {
        foreach ($keys as $key) {
            $source = is_array($source[$key] ?? null) ? $source[$key] : [];
        }

        return $source;
    }
}
