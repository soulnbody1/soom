<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\PaymentMethod;

final class CreatePaymentMethodAction
{
    public function execute(array $data): PaymentMethod
    {
        return PaymentMethod::create($data);
    }
}
