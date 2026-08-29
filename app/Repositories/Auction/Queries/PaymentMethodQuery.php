<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;

final class PaymentMethodQuery
{
    public function getActive(): Collection
    {
        return PaymentMethod::where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    public function getAll(): Collection
    {
        return PaymentMethod::orderBy('channel')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }
}
