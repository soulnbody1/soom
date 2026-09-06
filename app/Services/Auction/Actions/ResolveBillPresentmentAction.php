<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\BillRejectionReason;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\ValueObjects\BillReference;
use App\Models\Auction\PaymentTransaction;
use App\Repositories\Auction\PaymentBillingReferenceRepository;
use App\Services\Auction\Payments\Bills\BillQuery;
use App\Services\Auction\Payments\Bills\BillResolution;
use App\Services\Auction\Payments\Bills\PresentableBill;
use App\Services\Auction\Support\ObligationPayabilityRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Answers "what does this payer owe right now?".
 *
 * One algorithm serves every shape of the question. Finding the payer is the
 * only step that differs: a billing reference names them directly, while a bill
 * reference reaches them through the claim it names. From there a bill
 * reference and a purpose are filters over the same candidate set, not
 * alternative code paths, so a scheme that quotes a lasting subscription
 * number, one whose number is the claim itself, or one that sends both, all
 * resolve identically.
 *
 * The read is deliberately lock-free: this runs inside a synchronous inbound
 * request whose caller-side timeout is not ours to control, and taking auction
 * row locks here could stall settlement. What we present is advisory; the
 * binding check happens again, under locks, when money actually arrives.
 */
final class ResolveBillPresentmentAction
{
    public function __construct(
        private readonly PaymentBillingReferenceRepository $references,
        private readonly ObligationPayabilityRule $payability,
    ) {}

    public function execute(BillQuery $query): BillResolution
    {
        $payerId = $this->payerId($query);

        if ($payerId === null) {
            return BillResolution::rejected($this->unknownPayerReason($query));
        }

        $candidates = $this->candidates($query, $payerId);

        $bills = $candidates
            ->filter(fn (PaymentTransaction $transaction): bool => $this->payability->isPayable($transaction, lock: false))
            ->map(fn (PaymentTransaction $transaction): PresentableBill => $this->present($query, $transaction))
            ->values()
            ->all();

        if ($bills !== []) {
            return BillResolution::presented($bills);
        }

        if (! $query->isTargeted()) {
            return BillResolution::rejected(BillRejectionReason::NoPayableBills);
        }

        if (! $candidates->isEmpty()) {
            return BillResolution::rejected(BillRejectionReason::BillNotPayable);
        }

        return BillResolution::rejected($this->closedReason($query, $payerId));
    }

    private function payerId(BillQuery $query): ?int
    {
        if ($query->billingReference !== null) {
            $reference = $this->references->findByReference($query->providerCode, $query->billingReference);

            return $reference ? (int) $reference->user_id : null;
        }

        $payerId = $this->claim($query->providerCode, $query->billReference)?->user_id;

        return $payerId === null ? null : (int) $payerId;
    }

    /**
     * A quoted subscription number that names nobody and a claim number that
     * exists nowhere are different failures, and the payer deserves to be told
     * which one happened.
     */
    private function unknownPayerReason(BillQuery $query): BillRejectionReason
    {
        return $query->billingReference !== null
            ? BillRejectionReason::UnknownBillingReference
            : BillRejectionReason::BillNotFound;
    }

    /**
     * @return Collection<int, PaymentTransaction>
     */
    private function candidates(BillQuery $query, int $userId): Collection
    {
        $receivedAt = $query->receivedAt();

        return PaymentTransaction::query()
            ->with(['auction', 'user'])
            ->where('provider', $query->providerCode)
            ->where('user_id', $userId)
            ->where('status', PaymentTransactionStatus::Pending->value)
            ->whereNotNull('provider_transaction_id')
            ->where(fn ($builder) => $builder
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $receivedAt))
            ->when(
                $query->billReference,
                fn ($builder, BillReference $reference) => $builder->where('provider_transaction_id', $reference->value)
            )
            ->when(
                $query->purpose,
                fn ($builder, $purpose) => $builder->where('purpose', $purpose->value)
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * A claim that was settled is not a claim that never existed. Telling the
     * two apart is what lets a scheme say "already paid" rather than send the
     * payer looking for a typo.
     */
    private function closedReason(BillQuery $query, int $userId): BillRejectionReason
    {
        $claim = $this->claim($query->providerCode, $query->billReference, $userId);

        if (! $claim) {
            return BillRejectionReason::BillNotFound;
        }

        return $claim->status === PaymentTransactionStatus::Succeeded
            ? BillRejectionReason::BillAlreadyPaid
            : BillRejectionReason::BillNotPayable;
    }

    private function claim(string $providerCode, ?BillReference $reference, ?int $userId = null): ?PaymentTransaction
    {
        if ($reference === null) {
            return null;
        }

        return PaymentTransaction::query()
            ->where('provider', $providerCode)
            ->where('provider_transaction_id', $reference->value)
            ->when($userId, fn ($builder, int $id) => $builder->where('user_id', $id))
            ->first();
    }

    private function present(BillQuery $query, PaymentTransaction $transaction): PresentableBill
    {
        return new PresentableBill(
            billingReference: $query->billingReference,
            billReference: new BillReference((string) $transaction->provider_transaction_id),
            purpose: $transaction->purpose,
            principalMinor: (int) $transaction->amount_minor,
            customerFeeMinor: (int) $transaction->customer_fee_minor,
            currencyCode: (string) $transaction->currency_code,
            issuedAt: CarbonImmutable::instance($transaction->created_at),
            payableUntil: $transaction->expires_at,
            payerDisplayName: (string) $transaction->user?->name,
            auctionPublicId: (string) $transaction->auction?->public_id,
            auctionTitle: (string) $transaction->auction?->title,
            paymentTransactionPublicId: (string) $transaction->public_id,
        );
    }
}
