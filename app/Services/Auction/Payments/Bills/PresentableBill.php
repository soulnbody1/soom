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
 * Amounts are frozen from the auction configuration snapshot, and the platform
 * never presents a claim that can be part-paid: minimum and maximum both equal
 * the amount owed.
 */
final readonly class PresentableBill
{
    public function __construct(
        public BillingReference $billingReference,
        public BillReference $billReference,
        public PaymentPurpose $purpose,
        public int $amountMinor,
        public string $currencyCode,
        public CarbonImmutable $issuedAt,
        public ?CarbonImmutable $payableUntil,
        public string $payerDisplayName,
        public string $auctionPublicId,
        public string $auctionTitle,
        public string $paymentTransactionPublicId,
    ) {}

    public function allowsPartialPayment(): bool
    {
        return false;
    }

    public function minimumPayableMinor(): int
    {
        return $this->amountMinor;
    }

    public function maximumPayableMinor(): int
    {
        return $this->amountMinor;
    }
}
