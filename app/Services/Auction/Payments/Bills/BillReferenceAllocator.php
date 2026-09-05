<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Bills;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\Auction\ValueObjects\BillReference;
use App\Repositories\Auction\AuctionPaymentRepository;

/**
 * Allocates the reference for a single claim.
 *
 * Uniqueness is ultimately guaranteed by the unique index on
 * (provider, provider_transaction_id); this only avoids the pointless round trip
 * of proposing a reference that is already taken.
 */
final class BillReferenceAllocator
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly AuctionPaymentRepository $payments,
        private readonly ReferenceNumberGenerator $generator,
    ) {}

    public function allocate(string $providerCode): BillReference
    {
        $shape = $this->shape($providerCode);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = new BillReference($this->generator->generate($shape));

            if (! $this->payments->providerTransactionIdExists($providerCode, $candidate->value)) {
                return $candidate;
            }
        }

        throw AuctionException::domain('payment_bill_reference_unavailable');
    }

    public function shape(string $providerCode): array
    {
        return (array) config(
            "auction.payments.providers.{$providerCode}.bill_reference",
            ['length' => 12, 'charset' => 'numeric', 'check_digit' => true]
        );
    }
}
