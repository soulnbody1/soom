<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\PaymentMethod;
use App\Repositories\Auction\AuctionPaymentRepository;

final class CreatePaymentMethodAction
{
    public function __construct(
        private readonly AuctionPaymentRepository $payments,
    ) {}

    public function execute(array $data): PaymentMethod
    {
        return $this->payments->createPaymentMethod($data);
    }
}
