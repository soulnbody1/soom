<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\PaymentSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListPaymentSubmissionsAction
{
    public function execute(int $perPage): LengthAwarePaginator
    {
        return PaymentSubmission::with(['auction', 'paymentMethod', 'deposit', 'settlement'])
            ->latest('id')
            ->paginate($perPage);
    }
}
