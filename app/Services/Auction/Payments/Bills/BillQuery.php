<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Bills;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\ValueObjects\BillingReference;
use App\Domain\Auction\ValueObjects\BillReference;
use Carbon\CarbonImmutable;

/**
 * A request to see what a payer owes.
 *
 * A query carrying only a billing reference is the ordinary case and asks for
 * every open claim. Adding a bill reference narrows that same lookup to one
 * claim; it does not select a different code path. Adding a purpose narrows it
 * the same way.
 */
final readonly class BillQuery
{
    public function __construct(
        public string $providerCode,
        public BillingReference $billingReference,
        public ?BillReference $billReference = null,
        public ?PaymentPurpose $purpose = null,
        public ?CarbonImmutable $receivedAt = null,
    ) {}

    public function receivedAt(): CarbonImmutable
    {
        return $this->receivedAt ?? CarbonImmutable::now();
    }

    public function isTargeted(): bool
    {
        return $this->billReference !== null;
    }
}
