<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\PaymentSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class PaymentSubmissionQuery
{
    private const RELATIONS = [
        'auction',
        'paymentMethod',
        'deposit',
        'settlement',
        'user',
    ];

    /**
     * Paginate payment submissions for admin review with server-side filters.
     * Replaces ListPaymentSubmissionsAction query.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return PaymentSubmission::with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['purpose'] ?? null, fn (Builder $query, string $purpose) => $query->where('purpose', $purpose))
            ->when($filters['auction_id'] ?? null, function (Builder $query, string $auctionId): void {
                if (ctype_digit($auctionId)) {
                    $query->where('auction_id', (int) $auctionId);

                    return;
                }

                $query->whereHas('auction', fn (Builder $auction) => $auction->where('public_id', $auctionId));
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
