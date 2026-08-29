<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\PaymentTransaction;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Payments\PaymentTransactionStateMachine;
use App\Services\Auction\Payments\ProviderPayloadRedactor;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\PaymentObligationResolver;
use Illuminate\Support\Carbon;

final class SettleOnlinePaymentAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionRefundRepository $refunds,
        private readonly PaymentObligationResolver $obligations,
        private readonly PaymentTransactionStateMachine $stateMachine,
        private readonly ApplyPaymentSucceededAction $applyPaymentSucceeded,
        private readonly ProviderPayloadRedactor $redactor,
    ) {}

    public function execute(int $transactionId, ProviderPaymentStatus $status): PaymentTransaction
    {
        return $this->transaction->run(function () use ($transactionId, $status): PaymentTransaction {
            $transaction = $this->payments->lockTransaction($transactionId);

            $this->assertProviderReference($transaction, $status);

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

        $mismatch = $this->amountMismatch($transaction, $status);

        $transaction->forceFill([
            'status' => PaymentTransactionStatus::Succeeded,
            'provider_payload' => $this->redactor->redact($status->payload),
            'provider_fee_minor' => $status->providerFeeMinor,
            'settlement_reference' => $status->settlementReference,
            'processed_at' => Carbon::now(),
        ]);

        if ($mismatch !== null) {
            $transaction->forceFill(['failure_code' => $mismatch]);
            $this->payments->saveTransaction($transaction);

            $this->audit->log('auction.online_payment_mismatch', $transaction->auction, null, 'system', [
                'payment_transaction_public_id' => $transaction->public_id,
                'provider' => $transaction->provider,
                'mismatch' => $mismatch,
                'expected_amount_minor' => $transaction->amount_minor,
                'expected_currency_code' => $transaction->currency_code,
                'provider_amount_minor' => $status->amountMinor,
                'provider_currency_code' => $status->currencyCode,
            ]);
            $this->audit->outbox('auction.online_payment_mismatch', $transaction->auction, [
                'payment_transaction_id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'mismatch' => $mismatch,
            ]);

            return $transaction->refresh();
        }

        $obligationKey = $this->payableObligationKey($transaction);

        if ($obligationKey === null) {
            $this->payments->saveTransaction($transaction);
            $this->createAutomaticRefund($transaction, 'obligation_no_longer_payable');

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

    private function assertProviderReference(PaymentTransaction $transaction, ProviderPaymentStatus $status): void
    {
        if ((string) $transaction->provider_transaction_id !== $status->providerTransactionId) {
            throw AuctionException::domain('provider_transaction_mismatch');
        }
    }

    private function amountMismatch(PaymentTransaction $transaction, ProviderPaymentStatus $status): ?string
    {
        if ($status->currencyCode !== null && strtoupper($status->currencyCode) !== strtoupper((string) $transaction->currency_code)) {
            return 'currency_mismatch';
        }

        if ($status->amountMinor !== null && $status->amountMinor !== (int) $transaction->amount_minor) {
            return 'amount_mismatch';
        }

        return null;
    }

    private function payableObligationKey(PaymentTransaction $transaction): ?string
    {
        try {
            $auction = $this->auctions->lockAuctionForPayment((int) $transaction->auction_id);
            $obligation = $this->obligations->resolve($auction, (int) $transaction->user_id, $transaction->purpose);
        } catch (AuctionException) {
            return null;
        }

        $key = $obligation->key();

        if (! str_starts_with((string) $transaction->idempotency_key, $key.':')) {
            return null;
        }

        if ($obligation->amountMinor !== (int) $transaction->amount_minor) {
            return null;
        }

        if ($obligation->currencyCode !== (string) $transaction->currency_code) {
            return null;
        }

        if ($this->payments->lockSucceededTransactionForObligation($key)) {
            return null;
        }

        return $key;
    }

    private function createAutomaticRefund(PaymentTransaction $transaction, string $reason): void
    {
        $obligationKey = (string) $transaction->idempotency_key;
        [$obligationType, $obligationId] = $this->obligationParts($obligationKey);

        $this->refunds->firstOrCreateRefund(
            [
                'provider' => (string) $transaction->provider,
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
                'held_refund_amount_minor' => $transaction->amount_minor,
                'applied_refund_amount_minor' => 0,
                'currency_code' => $transaction->currency_code,
                'reason' => $reason,
            ]
        );

        $this->audit->log('auction.online_payment_late_success', $transaction->auction, null, 'system', [
            'payment_transaction_public_id' => $transaction->public_id,
            'provider' => $transaction->provider,
            'purpose' => $transaction->purpose->value,
            'amount_minor' => $transaction->amount_minor,
            'reason' => $reason,
        ]);
        $this->audit->outbox('auction.online_payment_late_success', $transaction->auction, [
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
