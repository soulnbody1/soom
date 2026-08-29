<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers\NGenius;

use RuntimeException;

final class NGeniusRequestException extends RuntimeException
{
    private const MASKED_KEYS = [
        'emailAddress',
        'firstName',
        'lastName',
        'cardholderName',
        'maskedPan',
        'cvv',
        'expiry',
        'cardToken',
    ];

    public function __construct(
        public readonly int $status,
        public readonly string $operation,
        public readonly string $url,
        public readonly array $body,
        public readonly array $requestPayload,
    ) {
        parent::__construct(sprintf(
            'N-Genius %s failed with status %d: %s',
            $operation,
            $status,
            $this->summary()
        ));
    }

    public function summary(): string
    {
        $errors = $this->errors();

        if ($errors !== []) {
            return implode(' | ', array_map(
                static fn (array $error): string => trim(($error['code'] ?? '').' '.($error['message'] ?? '')),
                $errors
            ));
        }

        foreach (['message', 'error_description', 'error', 'errorMessage'] as $key) {
            if (isset($this->body[$key]) && is_string($this->body[$key])) {
                return $this->body[$key];
            }
        }

        return $this->body === [] ? 'no response body' : 'see response body';
    }

    public function errors(): array
    {
        $candidates = $this->body['errors'] ?? $this->body['fieldErrors'] ?? $this->body['violations'] ?? [];

        if (! is_array($candidates)) {
            return [];
        }

        $errors = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $errors[] = [
                'code' => (string) ($candidate['code'] ?? $candidate['errorCode'] ?? ''),
                'field' => (string) ($candidate['field'] ?? $candidate['propertyPath'] ?? $candidate['path'] ?? ''),
                'message' => (string) ($candidate['message'] ?? $candidate['localizedMessage'] ?? $candidate['description'] ?? ''),
            ];
        }

        return $errors;
    }

    public function redactedPayload(): array
    {
        return $this->redact($this->requestPayload);
    }

    private function redact(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);

                continue;
            }

            $redacted[$key] = in_array((string) $key, self::MASKED_KEYS, true)
                ? $this->mask((string) $value)
                : $value;
        }

        return $redacted;
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, 2).str_repeat('*', max(1, mb_strlen($value) - 2));
    }
}
