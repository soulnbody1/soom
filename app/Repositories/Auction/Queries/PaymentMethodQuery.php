<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;

final class PaymentMethodQuery
{
    /**
     * Get all active payment methods.
     * Replaces ListPaymentMethodsAction query.
     */
    public function getActive(): Collection
    {
        return PaymentMethod::where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
