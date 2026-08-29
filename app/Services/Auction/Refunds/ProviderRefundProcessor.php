<?php

declare(strict_types=1);

namespace App\Services\Auction\Refunds;

use App\DTO\Auction\RefundProcessingResult;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Services\Auction\Payments\RefundCommand;
use Throwable;

final class ProviderRefundProcessor implements AuctionRefundProcessorInterface
{
    public function __construct(
        private readonly PaymentProviderFactory $providers,
        private readonly AuctionPaymentRepository $payments,
    ) {}

    public function process(RefundTransaction $refund): RefundProcessingResult
    {
        if (! $refund->payment_transaction_id) {
            return RefundProcessingResult::manualReviewRequired(
                'refund_source_payment_missing',
                'The refund is not linked to a provider payment transaction.'
            );
        }

        $payment = $this->payments->lockTransactionForRefund((int) $refund->payment_transaction_id);
        $providerTransactionId = (string) $payment->provider_transaction_id;

        if ($providerTransactionId === '') {
            return RefundProcessingResult::manualReviewRequired(
                'refund_provider_reference_missing',
                'The source payment has no provider transaction reference.'
            );
        }

        $provider = $this->providers->make((string) $refund->provider);
        $capabilities = $provider->capabilities();

        if ((int) $refund->amount_minor < (int) $payment->amount_minor && ! $capabilities->supportsPartialRefund) {
            return RefundProcessingResult::manualReviewRequired(
                'refund_partial_unsupported',
                'The provider does not support partial refunds.'
            );
        }

        try {
            return $provider->refund(new RefundCommand(
                providerTransactionId: $providerTransactionId,
                merchantReference: (string) $refund->public_id,
                amountMinor: (int) $refund->amount_minor,
                currencyCode: (string) $refund->currency_code,
                reason: (string) $refund->reason,
            ));
        } catch (Throwable $exception) {
            return RefundProcessingResult::retryableFailure(
                'provider_refund_exception',
                $exception->getMessage()
            );
        }
    }
}
