<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\PaymentMethodQuery;
use Illuminate\Database\Eloquent\Collection;

final class ListPaymentMethodsAction
{
    public function __construct(
        private readonly PaymentMethodQuery $query,
    ) {}

    public function execute(): Collection
    {
        return $this->query->getActive();
    }
}
