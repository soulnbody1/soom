<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

final class ProviderPayloadRedactor
{
    private const ALLOWED_KEYS = [
        'amount',
        'bank_code',
        'brand',
        'card_last_four',
        'card_scheme',
        'currency',
        'due_amount',
        'event_id',
        'event_type',
        'failure_code',
        'fee_on_biller',
        'issuer',
        'merchant_reference',
        'payment_channel',
        'payment_method',
        'payment_type',
        'provider_fee',
        'provider_transaction_id',
        'result_code',
        'result_description',
        'service_code',
        'settled_at',
        'settlement_reference',
        'statement_date',
        'status',
        'timestamp',
    ];

    private const MAX_VALUE_LENGTH = 190;

    public function redact(array $payload): array
    {
        $redacted = [];

        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_bool($value) || is_int($value)) {
                $redacted[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                $redacted[$key] = mb_substr($value, 0, self::MAX_VALUE_LENGTH);
            }
        }

        return $redacted;
    }
}
