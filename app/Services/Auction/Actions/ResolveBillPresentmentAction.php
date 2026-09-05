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
 * One algorithm serves both shapes of the question. A bill reference and a
 * purpose are filters over the same candidate set, not alternative code paths,
 * so a protocol that sends one, the other, both, or neither needs no change
 * here.
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
        $payer = $this->references->findByReference($query->providerCode, $query->billingReference);

        if (! $payer) {
            return BillResolution::rejected(BillRejectionReason::UnknownBillingReference);
        }

        $candidates = $this->candidates($query, (int) $payer->user_id);

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

        return BillResolution::rejected(
            $candidates->isEmpty()
                ? BillRejectionReason::BillNotFound
                : BillRejectionReason::BillNotPayable
        );
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

    private function present(BillQuery $query, PaymentTransaction $transaction): PresentableBill
    {
        return new PresentableBill(
            billingReference: $query->billingReference,
            billReference: new BillReference((string) $transaction->provider_transaction_id),
            purpose: $transaction->purpose,
            amountMinor: (int) $transaction->amount_minor,
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
