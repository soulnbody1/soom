<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Providers\NGenius;

final readonly class NGeniusOrder
{
    public function __construct(
        private array $order,
    ) {}

    public function reference(): string
    {
        return (string) ($this->order['reference'] ?? '');
    }

    public function merchantReference(): ?string
    {
        $defined = $this->order['merchantDefinedData'][NGeniusPayload::REFERENCE_KEY] ?? null;

        if (is_string($defined) && $defined !== '') {
            return $defined;
        }

        $merchantOrderReference = $this->order['merchantOrderReference'] ?? null;

        return is_string($merchantOrderReference) && $merchantOrderReference !== ''
            ? $merchantOrderReference
            : null;
    }

    public function state(): string
    {
        $payment = $this->latestPayment();

        return strtoupper((string) ($payment['state'] ?? ''));
    }

    public function resultCode(): ?string
    {
        $payment = $this->latestPayment();
        $code = $payment['authResponse']['resultCode'] ?? null;

        return is_scalar($code) && (string) $code !== '' ? (string) $code : null;
    }

    public function capturedAmountMinor(): ?int
    {
        foreach ($this->captures() as $capture) {
            $value = $capture['amount']['value'] ?? null;

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        $payment = $this->latestPayment();
        $value = $payment['amount']['value'] ?? $this->order['amount']['value'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function capturedCurrencyCode(): ?string
    {
        foreach ($this->captures() as $capture) {
            $code = $capture['amount']['currencyCode'] ?? null;

            if (is_string($code) && $code !== '') {
                return strtoupper($code);
            }
        }

        $payment = $this->latestPayment();
        $code = $payment['amount']['currencyCode'] ?? $this->order['amount']['currencyCode'] ?? null;

        return is_string($code) && $code !== '' ? strtoupper($code) : null;
    }

    public function refundHref(): ?string
    {
        $payment = $this->latestPayment();
        $href = $payment['_links']['cnp:refund']['href'] ?? null;

        if (is_string($href) && $href !== '') {
            return $href;
        }

        foreach ($this->captures() as $capture) {
            $self = $capture['_links']['self']['href'] ?? null;

            if (is_string($self) && $self !== '') {
                return rtrim($self, '/').'/refund';
            }
        }

        return null;
    }

    public function paymentHref(): ?string
    {
        $href = $this->order['_links']['payment']['href'] ?? null;

        return is_string($href) && $href !== '' ? $href : null;
    }

    public function safeSummary(): array
    {
        return [
            'provider_transaction_id' => $this->reference(),
            'merchant_reference' => $this->merchantReference(),
            'status' => $this->state(),
            'result_code' => $this->resultCode(),
            'amount' => $this->capturedAmountMinor(),
            'currency' => $this->capturedCurrencyCode(),
        ];
    }

    private function latestPayment(): array
    {
        $payments = $this->order['_embedded']['payment'] ?? [];

        if (! is_array($payments) || $payments === []) {
            return [];
        }

        $last = end($payments);

        return is_array($last) ? $last : [];
    }

    private function captures(): array
    {
        $payment = $this->latestPayment();
        $captures = $payment['_embedded']['cnp:capture'] ?? [];

        return is_array($captures) ? array_filter($captures, 'is_array') : [];
    }
}
