<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentTransaction;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Payments\PaymentIntent;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\OnlinePaymentMethodRule;
use App\Services\Auction\Support\PaymentObligationResolver;
use Illuminate\Support\Carbon;
use Throwable;

final class CreatePaymentIntentAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionPaymentRepository $payments,
        private readonly PaymentObligationResolver $obligations,
        private readonly PaymentProviderFactory $providers,
        private readonly OnlinePaymentMethodRule $methodRule,
    ) {}

    public function execute(
        Auction $auction,
        int $userId,
        PaymentPurpose $purpose,
        string $paymentMethodPublicId
    ): PaymentTransaction {
        $method = $this->payments->findActivePaymentMethod($paymentMethodPublicId);

        $prepared = $this->transaction->run(function () use ($auction, $userId, $purpose, $method): PaymentTransaction {
            $auction = $this->auctions->lockAuctionForPayment($auction->id);
            $obligation = $this->obligations->resolve($auction, $userId, $purpose);

            if ($obligation->amountMinor <= 0) {
                throw AuctionException::domain('zero_payment_not_allowed');
            }

            $this->methodRule->assertUsable($method, $auction, $purpose, $obligation);

            $providerCode = (string) $method->provider_code;
            $obligationKey = $obligation->key();

            if ($this->payments->lockSucceededTransactionForObligation($obligationKey)) {
                throw AuctionException::domain('payment_obligation_already_paid');
            }

            $pending = $this->payments->lockPendingOnlineTransactionForObligation($obligationKey, $providerCode);

            if ($pending && ! $this->isExpired($pending)) {
                if ($pending->checkout_instruction !== null) {
                    return $pending;
                }

                $this->claimCheckout($pending);

                return $pending;
            }

            $attempt = $this->payments->onlineAttemptCount($obligationKey, $providerCode) + 1;

            $created = $this->payments->firstOrCreateTransaction(
                [
                    'purpose' => $purpose->value,
                    'idempotency_key' => "{$obligationKey}:{$providerCode}:{$attempt}",
                ],
                [
                    'payment_submission_id' => null,
                    'auction_id' => $auction->id,
                    'user_id' => $userId,
                    'payment_method_id' => $method->id,
                    'status' => PaymentTransactionStatus::Pending,
                    'amount_minor' => $obligation->amountMinor,
                    'currency_code' => $obligation->currencyCode,
                    'provider' => $providerCode,
                    'expires_at' => Carbon::now()->addSeconds($this->intentTtlSeconds()),
                ]
            );

            $this->claimCheckout($created);

            return $created;
        });

        if ($prepared->checkout_instruction !== null) {
            return $prepared;
        }

        return $this->openCheckout($prepared, $method);
    }

    private function openCheckout(PaymentTransaction $transaction, PaymentMethod $method): PaymentTransaction
    {
        $provider = $this->providers->make((string) $transaction->provider);

        try {
            $instruction = $provider->createCheckout(new PaymentIntent(
                merchantReference: (string) $transaction->public_id,
                amountMinor: (int) $transaction->amount_minor,
                currencyCode: (string) $transaction->currency_code,
                purpose: $transaction->purpose->value,
                returnUrl: $this->returnUrl($transaction),
                metadata: ['auction_public_id' => (string) $transaction->auction?->public_id],
            ));
        } catch (Throwable $exception) {
            $this->markCheckoutFailed($transaction, $exception);

            throw AuctionException::domain('payment_provider_unavailable');
        }

        return $this->transaction->run(function () use ($transaction, $instruction, $method): PaymentTransaction {
            $locked = $this->payments->lockTransaction($transaction->id);

            $locked->forceFill([
                'provider_transaction_id' => $instruction->providerTransactionId,
                'checkout_instruction' => $instruction->toArray(),
                'checkout_claimed_at' => null,
                'expires_at' => Carbon::now()->addSeconds(
                    $instruction->expiresInSeconds ?? $this->intentTtlSeconds()
                ),
            ]);
            $this->payments->saveTransaction($locked);

            $this->audit->log('auction.online_payment_intent_created', $locked->auction, (int) $locked->user_id, 'user', [
                'payment_transaction_public_id' => $locked->public_id,
                'provider' => $locked->provider,
                'payment_method_code' => $method->code,
                'purpose' => $locked->purpose->value,
                'amount_minor' => $locked->amount_minor,
                'currency_code' => $locked->currency_code,
            ]);

            return $locked;
        });
    }

    private function claimCheckout(PaymentTransaction $transaction): void
    {
        $claimedAt = $transaction->checkout_claimed_at;

        if ($claimedAt !== null && $claimedAt->addSeconds($this->claimSeconds())->isFuture()) {
            throw AuctionException::domain('payment_checkout_in_progress', [], 409);
        }

        $transaction->forceFill(['checkout_claimed_at' => Carbon::now()]);
        $this->payments->saveTransaction($transaction);
    }

    private function isExpired(PaymentTransaction $transaction): bool
    {
        return $transaction->expires_at !== null && $transaction->expires_at->isPast();
    }

    private function markCheckoutFailed(PaymentTransaction $transaction, Throwable $exception): void
    {
        $this->transaction->run(function () use ($transaction, $exception): void {
            $locked = $this->payments->lockTransaction($transaction->id);

            if ($locked->status !== PaymentTransactionStatus::Pending) {
                return;
            }

            $locked->forceFill([
                'status' => PaymentTransactionStatus::Failed,
                'failure_code' => 'checkout_unavailable',
                'processed_at' => Carbon::now(),
            ]);
            $this->payments->saveTransaction($locked);

            $this->audit->log('auction.online_payment_checkout_failed', $locked->auction, (int) $locked->user_id, 'system', [
                'payment_transaction_public_id' => $locked->public_id,
                'provider' => $locked->provider,
                'error' => mb_substr($exception->getMessage(), 0, 190),
            ]);
        });
    }

    private function returnUrl(PaymentTransaction $transaction): string
    {
        return rtrim((string) config('auction.payments.return_url'), '/').'/'.$transaction->public_id;
    }

    private function intentTtlSeconds(): int
    {
        return max(60, (int) config('auction.payments.intent_ttl_seconds', 1800));
    }

    private function claimSeconds(): int
    {
        return max(5, (int) config('auction.payments.checkout_claim_seconds', 120));
    }
}
