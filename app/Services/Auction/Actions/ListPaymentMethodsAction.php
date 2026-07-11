<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;

final class ListPaymentMethodsAction
{
    public function execute(): Collection
    {
        return PaymentMethod::where('is_active', true)->orderBy('name')->get();
    }
}
