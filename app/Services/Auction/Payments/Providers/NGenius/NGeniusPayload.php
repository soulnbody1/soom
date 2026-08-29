<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers\NGenius;

use App\Domain\Auction\Enums\PaymentTransactionStatus;

final class NGeniusPayload
{
    public const REFERENCE_KEY = 'soomPaymentReference';

    private const SUCCEEDED = [
        'PURCHASED',
        'CAPTURED',
    ];

    private const FAILED = [
        'FAILED',
        'DECLINED',
        'AUTHORISATION_FAILED',
        'THREE_DS_FAILURE',
        'CAPTURE_FAILED',
        'PRE_AUTH_FRAUD_CHECK_REJECTED',
        'POST_AUTH_FRAUD_CHECK_REJECTED',
    ];

    private const CANCELLED = [
        'CANCELLED',
        'CANCELLATION_REQUESTED',
        'EXPIRED',
    ];

    private const REVERSED = [
        'REVERSED',
        'PURCHASE_REVERSED',
        'FULL_AUTH_REVERSED',
        'CAPTURE_VOIDED',
    ];

    public static function status(string $state): PaymentTransactionStatus
    {
        $state = strtoupper(trim($state));

        return match (true) {
            in_array($state, self::SUCCEEDED, true) => PaymentTransactionStatus::Succeeded,
            in_array($state, self::FAILED, true) => PaymentTransactionStatus::Failed,
            in_array($state, self::CANCELLED, true) => PaymentTransactionStatus::Cancelled,
            in_array($state, self::REVERSED, true) => PaymentTransactionStatus::Reversed,
            default => PaymentTransactionStatus::Pending,
        };
    }

    public static function merchantReference(array $payload): ?string
    {
        $candidates = [
            $payload['order']['merchantDefinedData'][self::REFERENCE_KEY] ?? null,
            $payload['merchantDefinedData'][self::REFERENCE_KEY] ?? null,
            $payload['order']['merchantOrderReference'] ?? null,
            $payload['merchantOrderReference'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    public static function orderReference(array $payload): string
    {
        $candidates = [
            $payload['order']['reference'] ?? null,
            $payload['reference'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
