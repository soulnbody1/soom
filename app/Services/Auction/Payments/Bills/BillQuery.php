<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Bills;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\ValueObjects\BillingReference;
use App\Domain\Auction\ValueObjects\BillReference;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A request to see what a payer owes.
 *
 * Either reference is enough to answer it. A billing reference names the payer
 * and asks for every open claim; a bill reference names one claim and reaches
 * the payer through it. Schemes that issue a lasting subscription number send
 * the first, schemes whose number is the claim itself send the second, and a
 * scheme that sends both is simply the first narrowed. None of them is a
 * separate code path, and a purpose narrows any of them the same way.
 */
final readonly class BillQuery
{
    public function __construct(
        public string $providerCode,
        public ?BillingReference $billingReference = null,
        public ?BillReference $billReference = null,
        public ?PaymentPurpose $purpose = null,
        public ?CarbonImmutable $receivedAt = null,
        public ?string $requestId = null,
    ) {
        if ($billingReference === null && $billReference === null) {
            throw new InvalidArgumentException('A bill query must carry a billing reference, a bill reference, or both.');
        }
    }

    public function receivedAt(): CarbonImmutable
    {
        return $this->receivedAt ?? CarbonImmutable::now();
    }

    public function isTargeted(): bool
    {
        return $this->billReference !== null;
    }
}
