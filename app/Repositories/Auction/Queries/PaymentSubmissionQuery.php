<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\PaymentSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PaymentSubmissionQuery
{
    private const RELATIONS = [
        'auction',
        'paymentMethod',
        'deposit',
        'settlement',
    ];

    /**
     * Paginate payment submissions for admin review.
     * Replaces ListPaymentSubmissionsAction query.
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return PaymentSubmission::with(self::RELATIONS)
            ->latest('id')
            ->paginate($perPage);
    }
}
