<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstNonTestingDatabase();
    }

    /**
     * Refuse to run any migration/transaction test against a MySQL database whose
     * name does not end with "_testing", so destructive operations can never hit a
     * development, staging, or production database.
     */
    private function guardAgainstNonTestingDatabase(): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            return;
        }

        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException(
                "Refusing to run tests: MySQL database '{$database}' does not end with '_testing'. "
                .'Aborting before any migration or transaction runs to avoid destructive operations.'
            );
        }
    }
}
