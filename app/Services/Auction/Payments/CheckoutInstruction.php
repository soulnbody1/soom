<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

final readonly class CheckoutInstruction
{
    public function __construct(
        public string $providerTransactionId,
        public string $type,
        public ?string $redirectUrl = null,
        public ?string $reference = null,
        public ?int $expiresInSeconds = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'redirect_url' => $this->redirectUrl,
            'reference' => $this->reference,
        ];
    }
}
