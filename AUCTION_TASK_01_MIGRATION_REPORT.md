# Auction Task 01 Migration Report

## 1. Root Cause

`database/migrations/2026_07_11_180000_rebuild_auction_schema.php` was unsafe for legacy upgrades.

The migration checked only:

```text
auctions table exists and has public_id
```

If that check failed, it dropped auction tables and stopped. On a legacy database where `2025_06_12_090000_create_auctions_table` was already recorded in the `migrations` table, Laravel would not run that older migration again. The result could be a database with auction tables removed and not recreated.

The fix makes the rebuild migration explicitly recreate the auction schema after dropping incompatible auction tables.

## 2. Legacy Schema

The simulated legacy database used by the test has:

- `2025_06_12_090000_create_auctions_table` recorded in `migrations`.
- Legacy `auctions` table without `public_id`.
- Legacy `auction_settlements` with a single-row-per-auction shape.
- Legacy `payment_methods` without the modern auction payment-method shape.

That reproduces the unsafe case: Laravel believes the old auction create migration already ran, but the actual schema is incompatible.

## 3. Final Schema

The final auction schema contains the current auction tables:

```text
payment_methods
auction_terms_versions
auctions
auction_media
auction_participants
auction_terms_acceptances
auction_deposits
payment_submissions
auction_bids
auction_settlements
auction_configuration_versions
auction_disputes
auction_winner_reassignments
payment_transactions
refund_transactions
auction_status_history
auction_activity_logs
auction_metrics
auction_views
outbox_messages
```

Important settlement columns verified by the test:

```text
sequence_number
is_current
current_marker
previous_settlement_id
winner_reassignment_id
remaining_amount_minor
```

## 4. Strategy

The chosen strategy is an explicit auction-schema rebuild for incompatible legacy schemas.

Why:

- The project has not entered production.
- The legacy auction schema is incompatible with the current application model.
- MySQL DDL is not fully transactional, so relying on `DB::transaction()` around DROP/CREATE would be misleading.
- The migration now recreates the auction schema immediately after dropping incompatible auction tables instead of relying on Laravel to rerun an already-recorded old migration.

The rebuild is scoped to auction-system tables and the auction payment/outbox tables used by the auction subsystem.

## 5. Migrations Changed

- `database/migrations/2026_07_11_180000_rebuild_auction_schema.php`
  - Added a stronger current-schema guard.
  - Expanded the scoped auction table drop list to include current and legacy auction tables.
  - Re-enables foreign key constraints in a `finally` block.
  - Recreates the auction schema after dropping incompatible legacy tables.

- `database/migrations/0001_01_01_000000_create_users_table.php`
  - Fresh MySQL migration was blocked because `users` referenced `countries`, `states`, and `cities` before those tables existed.
  - The location columns now create FK constraints only when the referenced table exists; otherwise they are indexed nullable ids.
  - This was required to run the requested Fresh `php artisan migrate` test.

- `database/migrations/2025_06_12_081316_create_ads_table.php`
  - Removed `after('longitude')` from a column created inside `Schema::create`, which caused MySQL syntax failure during Fresh migration.
  - This was required to run the requested Fresh `php artisan migrate` test.

## 6. Final Auction Tables

The final auction table list is verified by `AuctionMigrationSafetyTest` against `information_schema` on MySQL.

The test asserts Fresh and Legacy databases have identical columns, indexes, and foreign keys for all auction tables listed above.

## 7. Important Foreign Keys

Verified through `information_schema.KEY_COLUMN_USAGE` and `REFERENTIAL_CONSTRAINTS`:

- `auctions` references `users`, `categories`, `countries`, optional `states`, optional `cities`, and `auction_terms_versions`.
- `auction_bids` references `auctions`, `auction_participants`, `users`, and optionally previous bids.
- `auction_settlements` references `auctions`, `auction_bids`, users, and previous settlements.
- `payment_submissions` references auctions, deposits, settlements, users, and payment methods.
- `payment_transactions` references payment submissions, auctions, and users.
- `refund_transactions` references auctions, deposits/payment transactions, and users.

## 8. Important Unique Constraints

Verified in the MySQL schema fingerprint:

- Public id uniqueness on auction entities.
- `uq_auction_bid_sequence`
- `uq_auction_bid_idempotency`
- `uq_auction_participant_user`
- `uq_auction_deposit_user_type`
- `uq_payment_submission_idempotency`
- `uniq_provider_txn`
- `uq_refund_provider_refund_id`
- `uq_auction_settlement_sequence`
- `uq_auction_settlement_current`
- `uq_auction_settlement_bid`
- `event_id` uniqueness on `outbox_messages`

The old blocking settlement uniqueness:

```text
UNIQUE(auction_id)
```

is not present as `uq_auction_settlement_one`.

## 9. Important Indexes

Verified indexes include:

- `idx_auctions_status_starts`
- `idx_auctions_status_ends`
- `idx_auction_bids_rank`
- `idx_auction_bids_bidder`
- `idx_payment_submissions_auction_status`
- `idx_payment_transactions_auction`
- `idx_refunds_auction_status`
- `idx_outbox_status_available`
- `idx_outbox_aggregate`
- `idx_settlements_current_winner`

## 10. Legacy Data Handling

The current fix treats incompatible legacy auction tables as pre-production auction schema and rebuilds only the auction subsystem tables.

No non-auction project tables are dropped by the auction rebuild migration. The only non-auction migration edits were dependency fixes required to make Fresh MySQL migration runnable.

## 11. Fresh Database Test

Executed through:

```text
vendor\bin\phpunit --configuration phpunit.mysql.xml --filter=AuctionMigrationSafetyTest
```

The test created:

```text
soom_fresh_migration_testing
```

Then ran:

```text
php artisan migrate --force
```

Result:

```text
OK (1 test, 40 assertions)
```

Fresh schema had all expected auction tables and constraints.

## 12. Legacy Upgrade Test

The same test created:

```text
soom_legacy_migration_testing
```

It then:

- Ran prerequisite base migrations.
- Created an old auction schema without `public_id`.
- Marked `2025_06_12_090000_create_auctions_table` as already run.
- Ran `php artisan migrate --force`.
- Compared the final auction schema to the Fresh database.

Result:

```text
Fresh schema == Legacy upgraded schema
OK (1 test, 40 assertions)
```

## 13. Current Schema Test

The test ran:

```text
php artisan migrate --force
```

again on `soom_legacy_migration_testing`.

Result:

```text
Fresh schema == Legacy upgraded schema == Current rerun schema
OK (1 test, 40 assertions)
```

Manual reruns also returned:

```text
INFO  Nothing to migrate.
```

for both:

```text
soom_fresh_migration_testing
soom_legacy_migration_testing
```

## 14. Commands Run

Succeeded:

```text
php -l database/migrations/2026_07_11_180000_rebuild_auction_schema.php
php -l database/migrations/0001_01_01_000000_create_users_table.php
php -l database/migrations/2025_06_12_081316_create_ads_table.php
php -l tests/Feature/Auction/AuctionMigrationSafetyTest.php
vendor/bin/pint database/migrations/0001_01_01_000000_create_users_table.php tests/Feature/Auction/AuctionMigrationSafetyTest.php database/migrations/2026_07_11_180000_rebuild_auction_schema.php
vendor/bin/pint database/migrations/2025_06_12_081316_create_ads_table.php
vendor/bin/phpunit --configuration phpunit.mysql.xml --filter=AuctionMigrationSafetyTest
php artisan optimize:clear
php artisan test --filter=AuctionMigrationSafetyTest with DB_CONNECTION=mysql
php artisan migrate:status with DB_DATABASE=soom_fresh_migration_testing
php artisan migrate:status with DB_DATABASE=soom_legacy_migration_testing
php artisan migrate --force with DB_DATABASE=soom_fresh_migration_testing
php artisan migrate --force with DB_DATABASE=soom_legacy_migration_testing
```

Important results:

```text
vendor/bin/phpunit --configuration phpunit.mysql.xml --filter=AuctionMigrationSafetyTest
OK (1 test, 40 assertions)
```

```text
php artisan test --filter=AuctionMigrationSafetyTest with DB_CONNECTION=mysql
1 passed, 40 assertions
```

No `migrate:fresh` command was used.

## 15. Checks That Could Not Complete

```text
composer dump-autoload
```

Result:

```text
Timed out after 180 seconds while printing:
Generating optimized autoload files
```

The command was run, but did not finish within the timeout.

## 16. Remaining Risks

- The auction rebuild migration intentionally discards incompatible pre-production auction data when it detects an old/partial auction schema. This is documented because preserving arbitrary legacy auction rows would require a separate data migration map.
- The rebuild still uses the existing canonical auction create migration as the schema source when repairing an incompatible legacy schema; it no longer relies on Laravel migration history to rerun it.
- The Fresh MySQL run required two non-auction migration dependency fixes. They are migration-only fixes, not business-flow changes.
