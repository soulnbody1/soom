<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProviderCallGuard
{
    public function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('A content review provider must never be called inside a database transaction.');
        }
    }
}
