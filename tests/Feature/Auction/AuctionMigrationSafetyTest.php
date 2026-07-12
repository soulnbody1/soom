<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('auction-migrations')]
final class AuctionMigrationSafetyTest extends TestCase
{
    private const FRESH_DATABASE = 'soom_fresh_migration_testing';

    private const LEGACY_DATABASE = 'soom_legacy_migration_testing';

    private const AUCTION_TABLES = [
        'payment_methods',
        'auction_terms_versions',
        'auctions',
        'auction_media',
        'auction_participants',
        'auction_terms_acceptances',
        'auction_deposits',
        'payment_submissions',
        'auction_bids',
        'auction_settlements',
        'auction_configuration_versions',
        'auction_disputes',
        'auction_winner_reassignments',
        'payment_transactions',
        'refund_transactions',
        'auction_status_history',
        'auction_activity_logs',
        'auction_metrics',
        'auction_views',
        'outbox_messages',
    ];

    public function test_fresh_and_legacy_mysql_migrations_finish_with_same_auction_schema(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only migration safety test.');
        }

        $admin = $this->adminConnection();
        $this->recreateTestingDatabase($admin, self::FRESH_DATABASE);
        $this->recreateTestingDatabase($admin, self::LEGACY_DATABASE);

        $this->runArtisanForDatabase(self::FRESH_DATABASE, ['migrate', '--force']);
        $fresh = $this->auctionSchemaFingerprint(self::FRESH_DATABASE);

        $this->prepareLegacyAuctionDatabase(self::LEGACY_DATABASE);
        $this->runArtisanForDatabase(self::LEGACY_DATABASE, ['migrate', '--force']);
        $legacy = $this->auctionSchemaFingerprint(self::LEGACY_DATABASE);

        $this->runArtisanForDatabase(self::LEGACY_DATABASE, ['migrate', '--force']);
        $currentAfterRerun = $this->auctionSchemaFingerprint(self::LEGACY_DATABASE);

        $this->assertSame(self::AUCTION_TABLES, array_keys($fresh['tables']));
        $this->assertSame($fresh, $legacy);
        $this->assertSame($legacy, $currentAfterRerun);
        $this->assertArrayHasKey('remaining_amount_minor', $legacy['tables']['auction_settlements']['columns']);
        $this->assertArrayHasKey('current_marker', $legacy['tables']['auction_settlements']['columns']);
        $this->assertArrayHasKey('uq_auction_settlement_current', $legacy['tables']['auction_settlements']['indexes']);
        $this->assertArrayNotHasKey('uq_auction_settlement_one', $legacy['tables']['auction_settlements']['indexes']);
    }

    private function prepareLegacyAuctionDatabase(string $database): void
    {
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2025_06_11_102418_create_categories_table.php',
            '2025_06_12_081010_create_countries_table.php',
            '2025_06_12_081110_create_states_table.php',
            '2025_06_12_081210_create_cities_table.php',
            '2025_06_12_085500_drop_legacy_auction_tables.php',
        ] as $migration) {
            $this->runArtisanForDatabase($database, ['migrate', '--force', '--path=database/migrations/'.$migration]);
        }

        $pdo = $this->databaseConnection($database);
        $pdo->exec('CREATE TABLE payment_methods (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE auction_terms_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE auctions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, status VARCHAR(40) NOT NULL DEFAULT "draft") ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE auction_settlements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, auction_id BIGINT UNSIGNED NOT NULL UNIQUE, amount_due_minor BIGINT UNSIGNED NOT NULL DEFAULT 0, amount_paid_minor BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB');

        $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
        $statement = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
        $statement->execute(['2025_06_12_090000_create_auctions_table', $batch]);
    }

    private function runArtisanForDatabase(string $database, array $arguments): void
    {
        $this->assertTestingDatabaseName($database);

        $process = new Process(
            array_merge([PHP_BINARY, 'artisan'], $arguments),
            base_path(),
            $this->databaseEnvironment($database),
            null,
            120
        );
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getOutput().PHP_EOL.$process->getErrorOutput()
        );
    }

    private function recreateTestingDatabase(PDO $pdo, string $database): void
    {
        $this->assertTestingDatabaseName($database);

        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function auctionSchemaFingerprint(string $database): array
    {
        $pdo = $this->databaseConnection($database);
        $fingerprint = ['tables' => []];

        foreach (self::AUCTION_TABLES as $table) {
            $fingerprint['tables'][$table] = [
                'columns' => $this->columns($pdo, $database, $table),
                'indexes' => $this->indexes($pdo, $database, $table),
                'foreign_keys' => $this->foreignKeys($pdo, $database, $table),
            ];
        }

        return $fingerprint;
    }

    private function columns(PDO $pdo, string $database, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$database, $table]);

        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['COLUMN_NAME']] = [
                'type' => $column['COLUMN_TYPE'],
                'nullable' => $column['IS_NULLABLE'],
                'default' => $column['COLUMN_DEFAULT'],
                'extra' => $column['EXTRA'],
            ];
        }

        return $columns;
    }

    private function indexes(PDO $pdo, string $database, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute([$database, $table]);

        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
            $name = $index['INDEX_NAME'];
            $indexes[$name]['unique'] = ((int) $index['NON_UNIQUE']) === 0;
            $indexes[$name]['columns'][] = $index['COLUMN_NAME'];
        }

        return $indexes;
    }

    private function foreignKeys(PDO $pdo, string $database, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE kcu.TABLE_SCHEMA = ?
               AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION'
        );
        $statement->execute([$database, $table]);

        $foreignKeys = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $foreignKey) {
            $name = $foreignKey['CONSTRAINT_NAME'];
            $foreignKeys[$name]['columns'][] = $foreignKey['COLUMN_NAME'];
            $foreignKeys[$name]['references'][] = $foreignKey['REFERENCED_TABLE_NAME'].'.'.$foreignKey['REFERENCED_COLUMN_NAME'];
            $foreignKeys[$name]['delete_rule'] = $foreignKey['DELETE_RULE'];
        }

        return $foreignKeys;
    }

    private function adminConnection(): PDO
    {
        return new PDO(
            $this->dsn(null),
            (string) config('database.connections.mysql.username'),
            (string) config('database.connections.mysql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function databaseConnection(string $database): PDO
    {
        $this->assertTestingDatabaseName($database);

        return new PDO(
            $this->dsn($database),
            (string) config('database.connections.mysql.username'),
            (string) config('database.connections.mysql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function dsn(?string $database): string
    {
        $host = (string) config('database.connections.mysql.host');
        $port = (string) config('database.connections.mysql.port');
        $databasePart = $database ? "dbname={$database};" : '';

        return "mysql:host={$host};port={$port};{$databasePart}charset=utf8mb4";
    }

    private function databaseEnvironment(string $database): array
    {
        $this->assertTestingDatabaseName($database);

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => $database,
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];
    }

    private function assertTestingDatabaseName(string $database): void
    {
        $this->assertStringEndsWith('_testing', $database);
    }
}
