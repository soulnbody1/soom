<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Contracts;

interface VerifiesProviderConnection
{
    public function verifyConnection(): void;
}
