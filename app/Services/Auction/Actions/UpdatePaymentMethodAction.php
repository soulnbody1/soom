<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\PaymentMethod;

final class UpdatePaymentMethodAction
{
    public function execute(PaymentMethod $paymentMethod, array $data): PaymentMethod
    {
        $paymentMethod->update($data);

        return $paymentMethod->refresh();
    }
}
