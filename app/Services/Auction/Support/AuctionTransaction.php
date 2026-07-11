<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AuctionTransaction
{
    public function run(callable $callback, int $attempts = 3): mixed
    {
        $attempt = 0;

        beginning:
        $attempt++;

        try {
            return DB::transaction($callback, 1);
        } catch (QueryException $exception) {
            if ($attempt >= $attempts || ! $this->isDeadlock($exception)) {
                throw $exception;
            }

            Log::warning('Retrying auction transaction after deadlock.', [
                'attempt' => $attempt,
                'sql_state' => $exception->errorInfo[0] ?? null,
                'driver_code' => $exception->errorInfo[1] ?? null,
            ]);

            usleep(50_000 * $attempt);
            goto beginning;
        }
    }

    private function isDeadlock(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || in_array($driverCode, ['1205', '1213', '40001'], true);
    }
}
