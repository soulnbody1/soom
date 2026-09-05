<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\PaymentTransaction;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRepository;

/**
 * Single definition of "the obligation behind this transaction is still owed".
 *
 * Used by the settlement path (under row locks, before money is applied) and by
 * read-only paths such as bill presentment, so what we present can never drift
 * from what we accept.
 */
final class ObligationPayabilityRule
{
    public function __construct(
        private readonly AuctionRepository $auctions,
        private readonly AuctionPaymentRepository $payments,
        private readonly PaymentObligationResolver $obligations,
    ) {}

    /**
     * @param  bool  $lock  true inside a write transaction, false for read-only paths.
     * @return string|null the obligation key when still payable, null otherwise
     */
    public function payableObligationKey(PaymentTransaction $transaction, bool $lock = true): ?string
    {
        try {
            $auction = $lock
                ? $this->auctions->lockAuctionForPayment((int) $transaction->auction_id)
                : $this->auctions->findAuctionForPayment((int) $transaction->auction_id);

            $obligation = $this->obligations->resolve(
                $auction,
                (int) $transaction->user_id,
                $transaction->purpose,
                $lock
            );
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

        $alreadyPaid = $lock
            ? $this->payments->lockSucceededTransactionForObligation($key) !== null
            : $this->payments->succeededTransactionExistsForObligation($key);

        return $alreadyPaid ? null : $key;
    }

    public function isPayable(PaymentTransaction $transaction, bool $lock = true): bool
    {
        return $this->payableObligationKey($transaction, $lock) !== null;
    }
}
