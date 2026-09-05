<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Counts the queries a closure issues so N+1 regressions fail the suite instead
 * of quietly shipping. The Ad module's list endpoints are the reason this exists:
 * they render seven relations per row, so a missing eager load multiplies by the
 * page size rather than adding a constant.
 */
trait AssertsQueryCount
{
    /** @var list<string> */
    private array $recordedQueries = [];

    /**
     * Run $callback while recording every query, and return whatever it returned.
     * The captured SQL is available through recordedQueries() for diagnostics.
     */
    protected function countQueries(callable $callback): mixed
    {
        $this->recordedQueries = [];

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $callback();
        } finally {
            $this->recordedQueries = array_map(
                static fn (array $entry): string => (string) $entry['query'],
                DB::getQueryLog()
            );

            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return $result;
    }

    /**
     * Assert the closure stayed at or under a query budget.
     */
    protected function assertQueryCountAtMost(int $max, callable $callback): mixed
    {
        $result = $this->countQueries($callback);
        $actual = count($this->recordedQueries);

        $this->assertLessThanOrEqual(
            $max,
            $actual,
            "Expected at most {$max} queries, {$actual} ran:\n - ".implode("\n - ", $this->recordedQueries)
        );

        return $result;
    }

    /**
     * Assert no write reached the database — used to prove read endpoints stay
     * read-only after view/interaction recording moves onto the queue.
     */
    protected function assertNoWriteQueries(callable $callback): mixed
    {
        $result = $this->countQueries($callback);

        $writes = array_values(array_filter(
            $this->recordedQueries,
            static fn (string $sql): bool => (bool) preg_match('/^\s*(insert|update|delete)\b/i', $sql)
        ));

        $this->assertSame([], $writes, "Expected no writes, got:\n - ".implode("\n - ", $writes));

        return $result;
    }

    /** @return list<string> */
    protected function recordedQueries(): array
    {
        return $this->recordedQueries;
    }
}
