<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers\EFawateercom;

final readonly class MfepResult
{
    public const SUCCESS_CODE = 0;

    private function __construct(
        public int $errorCode,
        public string $errorDescription,
        public string $severity,
    ) {}

    public static function success(): self
    {
        return new self(self::SUCCESS_CODE, 'Success', 'Info');
    }

    public static function error(int $code, string $description): self
    {
        return new self($code, $description, 'Error');
    }

    public function toArray(): array
    {
        return [
            'ErrorCode' => $this->errorCode,
            'ErrorDesc' => $this->errorDescription,
            'Severity' => $this->severity,
        ];
    }
}
