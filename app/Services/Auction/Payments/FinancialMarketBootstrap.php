<?php

namespace App\Services\Auction\Payments;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Services\Auction\Payments\Bills\BillQuery;
use Illuminate\Support\Facades\DB;

final class FinancialMarketBootstrap
{
    public function forEvent(string $provider, ProviderEvent $event): ?int
    {
        $query = DB::table('payment_transactions')->where('provider', $provider);

        $transaction = (clone $query)->where('provider_transaction_id', $event->providerTransactionId)->first(['market_id']);
        if ($transaction === null && $event->merchantReference !== null && $event->merchantReference !== '') {
            $transaction = (clone $query)->where('public_id', $event->merchantReference)->first(['market_id']);
        }

        return $transaction?->market_id === null ? null : (int) $transaction->market_id;
    }

    public function forBillQuery(string $provider, BillQuery $query): ?int
    {
        $transactions = DB::table('payment_transactions')->where('payment_transactions.provider', $provider);

        if ($query->billReference !== null) {
            $transactions->where('payment_transactions.provider_transaction_id', $query->billReference->value);
        } else {
            $transactions->join('payment_billing_references', function ($join) use ($provider, $query): void {
                $join->on('payment_billing_references.user_id', '=', 'payment_transactions.user_id')
                    ->where('payment_billing_references.provider', '=', $provider)
                    ->where('payment_billing_references.reference', '=', $query->billingReference->value);
            });
        }

        $marketIds = $transactions
            ->whereNotNull('payment_transactions.market_id')
            ->distinct()
            ->pluck('payment_transactions.market_id');

        if ($marketIds->count() > 1) {
            throw AuctionException::domain('payment_market_ambiguous', [], 409);
        }

        return $marketIds->isEmpty() ? null : (int) $marketIds->first();
    }
}
