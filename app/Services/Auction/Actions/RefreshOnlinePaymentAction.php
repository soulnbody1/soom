<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Models\Auction\PaymentTransaction;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use Illuminate\Support\Carbon;
use Throwable;

final class RefreshOnlinePaymentAction
{
    public function __construct(
        private readonly PaymentProviderFactory $providers,
        private readonly SettleOnlinePaymentAction $settle,
    ) {}

    public function execute(PaymentTransaction $transaction, bool $allowExpiry = false): PaymentTransaction
    {
        if ($transaction->status !== PaymentTransactionStatus::Pending) {
            return $transaction;
        }

        if ($transaction->provider_transaction_id === null) {
            return $transaction;
        }

        $provider = $this->providers->make((string) $transaction->provider);

        try {
            $status = $provider->fetchStatus((string) $transaction->provider_transaction_id);
        } catch (Throwable) {
            return $transaction;
        }

        if ($status->status === PaymentTransactionStatus::Pending) {
            if (! $allowExpiry || ! $this->isExpired($transaction)) {
                return $transaction;
            }

            $status = new ProviderPaymentStatus(
                status: PaymentTransactionStatus::Expired,
                providerTransactionId: $status->providerTransactionId,
                failureCode: 'intent_expired',
                payload: $status->payload,
            );
        }

        return $this->settle->execute((int) $transaction->id, $status);
    }

    private function isExpired(PaymentTransaction $transaction): bool
    {
        return $transaction->expires_at !== null && $transaction->expires_at->lessThan(Carbon::now());
    }
}
