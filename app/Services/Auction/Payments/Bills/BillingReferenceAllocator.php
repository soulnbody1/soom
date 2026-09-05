<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Bills;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\Auction\ValueObjects\BillingReference;
use App\Repositories\Auction\PaymentBillingReferenceRepository;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Database\QueryException;

/**
 * Hands a payer the one reference they will quote to this provider for life.
 *
 * Allocation is lazy: a payer only gets a reference the first time they reach
 * for a bill-rail payment.
 */
final class BillingReferenceAllocator
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PaymentBillingReferenceRepository $references,
        private readonly ReferenceNumberGenerator $generator,
        private readonly AuctionTransaction $transaction,
    ) {}

    public function forUser(int $userId, string $providerCode): BillingReference
    {
        $existing = $this->references->findForUser($userId, $providerCode);

        if ($existing) {
            return $existing->billingReference();
        }

        return $this->transaction->run(function () use ($userId, $providerCode): BillingReference {
            $existing = $this->references->findForUser($userId, $providerCode);

            if ($existing) {
                return $existing->billingReference();
            }

            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $candidate = new BillingReference($this->generator->generate($this->shape($providerCode)));

                if ($this->references->findByReference($providerCode, $candidate)) {
                    continue;
                }

                try {
                    $this->references->create($userId, $providerCode, $candidate);

                    return $candidate;
                } catch (QueryException $exception) {
                    // Either the reference or the payer was claimed concurrently.
                    $concurrent = $this->references->findForUser($userId, $providerCode);

                    if ($concurrent) {
                        return $concurrent->billingReference();
                    }

                    if (! $this->isUniqueViolation($exception)) {
                        throw $exception;
                    }
                }
            }

            throw AuctionException::domain('payment_billing_reference_unavailable');
        });
    }

    public function shape(string $providerCode): array
    {
        return (array) config(
            "auction.payments.providers.{$providerCode}.billing_reference",
            ['length' => 10, 'charset' => 'numeric', 'check_digit' => true]
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || ($exception->errorInfo[1] ?? null) === 1062;
    }
}
