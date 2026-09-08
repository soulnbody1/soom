<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\Auction\ValueObjects\Currency;
use App\Models\Auction\PaymentTransaction;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Services\Auction\Payments\PaymentTransactionStateMachine;
use App\Services\Auction\Payments\ProviderPayloadRedactor;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\ObligationPayabilityRule;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class SettleOnlinePaymentAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionRefundRepository $refunds,
        private readonly ObligationPayabilityRule $payability,
        private readonly PaymentTransactionStateMachine $stateMachine,
        private readonly ApplyPaymentSucceededAction $applyPaymentSucceeded,
        private readonly ProviderPayloadRedactor $redactor,
    ) {}

    public function execute(int $transactionId, ProviderPaymentStatus $status): PaymentTransaction
    {
        return $this->transaction->run(function () use ($transactionId, $status): PaymentTransaction {
            $transaction = $this->payments->lockTransaction($transactionId);

            $this->bindProviderReference($transaction, $status);

            if ($transaction->status === $status->status) {
                return $transaction;
            }

            if ($transaction->status !== PaymentTransactionStatus::Pending) {
                $this->stateMachine->assert($transaction->status, $status->status);
            }

            if ($status->status !== PaymentTransactionStatus::Succeeded) {
                return $this->closeUnsuccessful($transaction, $status);
            }

            return $this->succeed($transaction, $status);
        });
    }

    private function succeed(PaymentTransaction $transaction, ProviderPaymentStatus $status): PaymentTransaction
    {
        $this->stateMachine->assert($transaction->status, PaymentTransactionStatus::Succeeded);

        $mismatch = $this->mismatchReason($transaction, $status);

        $transaction->forceFill([
            'status' => PaymentTransactionStatus::Succeeded,
            'provider_payload' => $this->redactor->redact($status->payload),
            'provider_fee_minor' => $status->providerFeeMinor,
            'provider_event_id' => $status->providerEventId,
            'settlement_reference' => $status->settlementReference,
            'settled_at' => $status->settledAt,
            'processed_at' => Carbon::now(),
        ]);

        if ($mismatch !== null) {
            return $this->recordMismatchedCapture($transaction, $status, $mismatch);
        }

        $obligationKey = $this->payability->payableObligationKey($transaction, lock: true);

        if ($obligationKey === null) {
            $this->payments->saveTransaction($transaction);
            $this->createAutomaticRefund($transaction, 'obligation_no_longer_payable', (string) $transaction->provider);

            return $transaction->refresh();
        }

        $transaction->forceFill(['successful_obligation_key' => $obligationKey]);
        $this->payments->saveTransaction($transaction);

        $this->applyPaymentSucceeded->execute($transaction, null, 'system');

        $this->audit->log('auction.online_payment_succeeded', $transaction->auction, (int) $transaction->user_id, 'system', [
            'payment_transaction_public_id' => $transaction->public_id,
            'provider' => $transaction->provider,
            'purpose' => $transaction->purpose->value,
            'amount_minor' => $transaction->amount_minor,
            'currency_code' => $transaction->currency_code,
        ]);
        $this->audit->outbox('auction.online_payment_succeeded', $transaction->auction, [
            'payment_transaction_id' => $transaction->id,
            'user_id' => $transaction->user_id,
            'purpose' => $transaction->purpose->value,
        ]);

        return $transaction->refresh();
    }

    private function recordMismatchedCapture(
        PaymentTransaction $transaction,
        ProviderPaymentStatus $status,
        string $mismatch
    ): PaymentTransaction {
        $customerFee = (int) $transaction->customer_fee_minor;
        $expectedAmount = (int) $transaction->amount_minor;
        $expectedPayable = $transaction->payableAmountMinor();
        $expectedCurrency = (string) $transaction->currency_code;
        $capturedAmount = $status->amountMinor ?? $expectedPayable;
        $capturedCurrency = $status->currencyCode === null ? $expectedCurrency : strtoupper($status->currencyCode);
        $capturedPrincipal = max(0, $capturedAmount - $customerFee);
        $representable = $capturedPrincipal > 0 && $this->isRepresentableCurrency($capturedCurrency);

        $transaction->forceFill([
            'failure_code' => $mismatch,
            'captured_amount_minor' => $capturedAmount,
            'captured_currency_code' => $capturedCurrency,
            'amount_minor' => $representable ? $capturedPrincipal : $expectedAmount,
            'currency_code' => $representable ? $capturedCurrency : $expectedCurrency,
        ]);
        $this->payments->saveTransaction($transaction);

        $this->audit->log('auction.online_payment_mismatch', $transaction->auction, null, 'system', [
            'payment_transaction_public_id' => $transaction->public_id,
            'provider' => $transaction->provider,
            'mismatch' => $mismatch,
            'expected_amount_minor' => $expectedAmount,
            'expected_payable_minor' => $expectedPayable,
            'customer_fee_minor' => $customerFee,
            'expected_currency_code' => $expectedCurrency,
            'provider_amount_minor' => $status->amountMinor,
            'provider_currency_code' => $status->currencyCode,
            'captured_amount_recorded' => $representable,
        ]);
        $this->audit->outbox('auction.online_payment_mismatch', $transaction->auction, [
            'payment_transaction_id' => $transaction->id,
            'user_id' => $transaction->user_id,
            'mismatch' => $mismatch,
        ]);

        $this->createAutomaticRefund(
            $transaction,
            $mismatch,
            $representable ? (string) $transaction->provider : 'manual'
        );

        return $transaction->refresh();
    }

    private function closeUnsuccessful(PaymentTransaction $transaction, ProviderPaymentStatus $status): PaymentTransaction
    {
        $this->stateMachine->assert($transaction->status, $status->status);

        $transaction->forceFill([
            'status' => $status->status,
            'failure_code' => $status->failureCode,
            'provider_payload' => $this->redactor->redact($status->payload),
            'processed_at' => Carbon::now(),
        ]);
        $this->payments->saveTransaction($transaction);

        $this->audit->log('auction.online_payment_closed', $transaction->auction, (int) $transaction->user_id, 'system', [
            'payment_transaction_public_id' => $transaction->public_id,
            'provider' => $transaction->provider,
            'status' => $status->status->value,
            'failure_code' => $status->failureCode,
        ]);

        return $transaction->refresh();
    }

    private function bindProviderReference(PaymentTransaction $transaction, ProviderPaymentStatus $status): void
    {
        $local = (string) $transaction->provider_transaction_id;

        if ($local === $status->providerTransactionId) {
            return;
        }

        if ($local !== '') {
            throw AuctionException::domain('provider_transaction_mismatch');
        }

        if ($status->providerTransactionId === '') {
            throw AuctionException::domain('provider_transaction_mismatch');
        }

        $transaction->forceFill(['provider_transaction_id' => $status->providerTransactionId]);
        $this->payments->saveTransaction($transaction);

        $this->audit->log('auction.online_payment_reference_recovered', $transaction->auction, null, 'system', [
            'payment_transaction_public_id' => $transaction->public_id,
            'provider' => $transaction->provider,
            'provider_transaction_id' => $status->providerTransactionId,
        ]);
    }

    private function mismatchReason(PaymentTransaction $transaction, ProviderPaymentStatus $status): ?string
    {
        if ($status->currencyCode !== null && strtoupper($status->currencyCode) !== strtoupper((string) $transaction->currency_code)) {
            return $this->isRepresentableCurrency(strtoupper($status->currencyCode))
                ? 'provider_currency_mismatch'
                : 'provider_currency_unsupported';
        }

        if ($status->amountMinor !== null && $status->amountMinor !== $transaction->payableAmountMinor()) {
            return 'provider_amount_mismatch';
        }

        return null;
    }

    private function isRepresentableCurrency(string $currencyCode): bool
    {
        try {
            Currency::fromCode($currencyCode);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function createAutomaticRefund(PaymentTransaction $transaction, string $reason, string $provider): void
    {
        $obligationKey = (string) $transaction->idempotency_key;
        [$obligationType, $obligationId] = $this->obligationParts($obligationKey);

        $this->refunds->firstOrCreateRefund(
            [
                'provider' => $provider,
                'idempotency_key' => "payment:{$transaction->id}:{$reason}",
            ],
            [
                'auction_id' => $transaction->auction_id,
                'deposit_id' => null,
                'payment_transaction_id' => $transaction->id,
                'obligation_type' => $obligationType,
                'obligation_id' => $obligationId,
                'user_id' => $transaction->user_id,
                'status' => RefundTransactionStatus::Pending,
                'amount_minor' => $transaction->amount_minor,
                'held_refund_amount_minor' => 0,
                'applied_refund_amount_minor' => 0,
                'currency_code' => $transaction->currency_code,
                'reason' => $reason,
            ]
        );

        $this->audit->log('auction.online_payment_auto_refund_planned', $transaction->auction, null, 'system', [
            'payment_transaction_public_id' => $transaction->public_id,
            'payment_provider' => $transaction->provider,
            'refund_provider' => $provider,
            'purpose' => $transaction->purpose->value,
            'amount_minor' => $transaction->amount_minor,
            'currency_code' => $transaction->currency_code,
            'reason' => $reason,
        ]);
        $this->audit->outbox('auction.online_payment_auto_refund_planned', $transaction->auction, [
            'payment_transaction_id' => $transaction->id,
            'user_id' => $transaction->user_id,
            'reason' => $reason,
        ]);
    }

    private function obligationParts(string $idempotencyKey): array
    {
        $segments = explode(':', $idempotencyKey);

        if (count($segments) < 2 || ! ctype_digit($segments[1])) {
            return [null, null];
        }

        return [$segments[0], (int) $segments[1]];
    }
}
