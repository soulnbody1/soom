<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;

final class PaymentTransactionStateMachine
{
    private const ALLOWED = [
        'pending' => [
            PaymentTransactionStatus::Succeeded,
            PaymentTransactionStatus::Failed,
            PaymentTransactionStatus::Cancelled,
            PaymentTransactionStatus::Expired,
        ],
        'succeeded' => [
            PaymentTransactionStatus::Reversed,
        ],
        'failed' => [],
        'cancelled' => [],
        'expired' => [],
        'reversed' => [],
    ];

    public function allows(PaymentTransactionStatus $from, PaymentTransactionStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::ALLOWED[$from->value] ?? [], true);
    }

    public function assert(PaymentTransactionStatus $from, PaymentTransactionStatus $to): void
    {
        if (! $this->allows($from, $to)) {
            throw AuctionException::domain('payment_transaction_transition_invalid', [
                'from' => $from->value,
                'to' => $to->value,
            ]);
        }
    }
}
