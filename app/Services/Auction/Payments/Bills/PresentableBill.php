<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Bills;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\ValueObjects\BillingReference;
use App\Domain\Auction\ValueObjects\BillReference;
use Carbon\CarbonImmutable;

/**
 * One claim a payer may settle right now.
 *
 * Two amounts are carried, never one. The principal is the obligation itself,
 * frozen from the auction configuration snapshot, and it is what a refund
 * returns. The customer fee is what a scheme charges the payer for the
 * convenience of paying, and it is not part of the obligation. What the payer
 * is asked for is their sum, and that total is the only amount a scheme is ever
 * quoted — so the platform never presents a claim that can be part-paid:
 * minimum and maximum both equal the payable total.
 *
 * A billing reference is optional because not every scheme has one: where the
 * claim number is the only identifier, there is nothing else to echo back.
 */
final readonly class PresentableBill
{
    public function __construct(
        public ?BillingReference $billingReference,
        public BillReference $billReference,
        public PaymentPurpose $purpose,
        public int $principalMinor,
        public int $customerFeeMinor,
        public string $currencyCode,
        public CarbonImmutable $issuedAt,
        public ?CarbonImmutable $payableUntil,
        public string $payerDisplayName,
        public string $auctionPublicId,
        public string $auctionTitle,
        public string $paymentTransactionPublicId,
    ) {}

    public function payableMinor(): int
    {
        return $this->principalMinor + $this->customerFeeMinor;
    }

    public function allowsPartialPayment(): bool
    {
        return false;
    }

    public function minimumPayableMinor(): int
    {
        return $this->payableMinor();
    }

    public function maximumPayableMinor(): int
    {
        return $this->payableMinor();
    }
}
