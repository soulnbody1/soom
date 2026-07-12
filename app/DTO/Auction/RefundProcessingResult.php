<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\RefundProcessingOutcome;

final readonly class RefundProcessingResult extends BaseAuctionDTO
{
    public function __construct(
        public RefundProcessingOutcome $outcome,
        public ?string $providerRefundId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $providerResponse = [],
    ) {}

    public static function succeeded(string $providerRefundId, array $providerResponse = []): self
    {
        return new self(RefundProcessingOutcome::Succeeded, $providerRefundId, providerResponse: $providerResponse);
    }

    public static function retryableFailure(string $errorCode, string $errorMessage, array $providerResponse = []): self
    {
        return new self(RefundProcessingOutcome::RetryableFailure, errorCode: $errorCode, errorMessage: $errorMessage, providerResponse: $providerResponse);
    }

    public static function nonRetryableFailure(string $errorCode, string $errorMessage, array $providerResponse = []): self
    {
        return new self(RefundProcessingOutcome::NonRetryableFailure, errorCode: $errorCode, errorMessage: $errorMessage, providerResponse: $providerResponse);
    }

    public static function manualReviewRequired(string $errorCode, string $errorMessage, array $providerResponse = []): self
    {
        return new self(RefundProcessingOutcome::ManualReviewRequired, errorCode: $errorCode, errorMessage: $errorMessage, providerResponse: $providerResponse);
    }

    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'provider_refund_id' => $this->providerRefundId,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'provider_response' => $this->providerResponse,
        ];
    }
}
