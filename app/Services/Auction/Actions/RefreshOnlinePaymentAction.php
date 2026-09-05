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

        if (! $provider->capabilities()->supportsInquiry) {
            return $this->expireWithoutInquiry($transaction, $allowExpiry);
        }

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

    /**
     * Providers without a status inquiry API have nothing to poll: the pending
     * intent stays pending until an event settles it, and only local expiry can
     * close it. Without this branch such intents would never expire, because the
     * expiry check below sits behind a successful fetchStatus() call.
     */
    private function expireWithoutInquiry(PaymentTransaction $transaction, bool $allowExpiry): PaymentTransaction
    {
        if (! $allowExpiry || ! $this->isExpired($transaction)) {
            return $transaction;
        }

        return $this->settle->execute((int) $transaction->id, new ProviderPaymentStatus(
            status: PaymentTransactionStatus::Expired,
            providerTransactionId: (string) $transaction->provider_transaction_id,
            failureCode: 'intent_expired',
        ));
    }

    private function isExpired(PaymentTransaction $transaction): bool
    {
        return $transaction->expires_at !== null && $transaction->expires_at->lessThan(Carbon::now());
    }
}
