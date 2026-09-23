<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\RefundTransaction;
use App\Models\Market;
use App\Support\Market\MarketContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class AuctionSellerPayoutRepository
{
    public function lockById(int $payoutId): AuctionSellerPayout
    {
        return AuctionSellerPayout::whereKey($payoutId)->lockForUpdate()->firstOrFail();
    }

    public function findBySettlementId(int $settlementId): ?AuctionSellerPayout
    {
        return AuctionSellerPayout::where('settlement_id', $settlementId)->first();
    }

    public function create(array $attributes): AuctionSellerPayout
    {
        return AuctionSellerPayout::create($attributes);
    }

    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        return AuctionSellerPayout::with([
            'market:id,code,web_host',
            'auction:id,market_id,public_id,title',
            'auction.market:id,code,web_host',
            'seller:id,name',
        ])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['auction_id'] ?? null, function (Builder $query, string $auctionId): void {
                $query->whereHas('auction', fn (Builder $auction) => $auction->where('public_id', $auctionId));
            })
            ->when($filters['seller_id'] ?? null, fn (Builder $query, int $sellerId) => $query->where('seller_id', $sellerId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('public_id', $search)
                        ->orWhere('transfer_reference', $search)
                        ->orWhereHas('auction', function (Builder $auction) use ($search): void {
                            $auction->where('public_id', $search)->orWhere('title', 'like', "%{$search}%");
                        })
                        ->orWhereHas('seller', fn (Builder $seller) => $seller->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(
                ! ($filters['status'] ?? null),
                fn (Builder $query) => $query->orderByRaw("case when status in ('pending', 'manual_review', 'on_hold', 'processing') then 0 else 1 end")
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array<string, array{count: int, amount_minor: int, currency_code: string}>
     */
    public function summaryByStatus(): array
    {
        $rows = AuctionSellerPayout::query()
            ->selectRaw('status, currency_code, count(*) as payout_count, coalesce(sum(amount_minor), 0) as total_minor')
            ->groupBy('status', 'currency_code')
            ->get();

        $state = app(MarketContext::class)->state();
        $fallbackCurrency = $state->market?->currency_code
            ?? (string) ($rows->first()->currency_code ?? Market::query()->where('is_active', true)->value('currency_code') ?? 'JOD');

        $summary = [];
        foreach (SellerPayoutStatus::cases() as $status) {
            $summary[$status->value] = ['count' => 0, 'amount_minor' => 0, 'currency_code' => $fallbackCurrency];
        }

        foreach ($rows as $row) {
            $key = (string) $row->status->value;
            $summary[$key] = [
                'count' => $summary[$key]['count'] + (int) $row->payout_count,
                'amount_minor' => $summary[$key]['amount_minor'] + (int) $row->total_minor,
                'currency_code' => (string) $row->currency_code,
            ];
        }

        return $summary;
    }

    public function sellerDepositRefund(AuctionSellerPayout $payout): ?RefundTransaction
    {
        return RefundTransaction::where('auction_id', $payout->auction_id)
            ->where('user_id', $payout->seller_id)
            ->whereHas('deposit', fn (Builder $deposit) => $deposit->where('type', 'seller'))
            ->latest('id')
            ->first();
    }

    public function paginateForSeller(int $sellerId, array $filters, int $perPage): LengthAwarePaginator
    {
        return AuctionSellerPayout::with([
            'market:id,code,web_host',
            'auction:id,market_id,public_id,title',
            'auction.market:id,code,web_host',
        ])
            ->where('seller_id', $sellerId)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function save(AuctionSellerPayout $payout): void
    {
        $payout->save();
    }
}
