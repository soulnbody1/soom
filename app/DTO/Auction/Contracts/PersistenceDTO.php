<?php

declare(strict_types=1);

namespace App\DTO\Auction\Contracts;

interface PersistenceDTO
{
    public function toPersistenceArray(): array;
}
