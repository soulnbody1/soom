# AUCTION TASK 02 - Payment Uniqueness Report

## 1. Root Cause

Payment submissions were idempotent only by request key:

```text
auction_id + user_id + purpose + idempotency_key
```

This allowed two different submissions with different idempotency keys to target the same financial obligation. Review approval also created the successful `PaymentTransaction` before the deposit row was locked for deposit payments, and `payment_transactions` had no database-level unique key for the paid obligation.

## 2. Final Financial Obligation Definition

The final obligation identity is explicit and stable:

```text
deposit:{auction_deposits.id}
settlement:{auction_settlements.id}
```

This covers:

- Seller Deposit: `deposit:{id}`
- Bidder Deposit: `deposit:{id}`
- Winner Settlement: `settlement:{id}`

The key is generated only through `App\Services\Auction\Support\FinancialObligationKey`.

## 3. Submission To Obligation Binding

`payment_submissions.deposit_id` binds seller/bidder deposits.

`payment_submissions.settlement_id` binds winner settlements.

The submit flow now locks the target deposit or settlement inside the transaction, checks for an existing successful payment, and blocks a second `pending_review` submission for the same obligation.

## 4. Transaction To Obligation Binding

Successful payment transactions now store:

```text
payment_transactions.successful_obligation_key
```

It is `NULL` for non-successful historical/future transaction rows and a unique obligation key for successful rows.

## 5. New Database Constraints

Added to fresh schema and upgrade migration:

```text
UNIQUE(payment_submission_id) as uq_payment_transaction_submission
UNIQUE(successful_obligation_key) as uq_payment_successful_obligation
```

Existing provider protection remains:

```text
UNIQUE(provider, provider_transaction_id) as uniq_provider_txn
```

The upgrade migration backfills `successful_obligation_key` for existing succeeded transactions from their submission `deposit_id` or `settlement_id`, then refuses to add the unique keys if duplicate historical successful payments already exist.

## 6. Duplicate Active Submissions

The submit action now treats `pending_review` as the active state.

Result:

- Existing `pending_review` for the same obligation: blocked with `active_payment_submission_exists`.
- Existing `rejected`: new submission is allowed.
- Existing successful payment or paid obligation: blocked with `payment_obligation_already_paid`.

## 7. Duplicate Successful Payments

The review action now:

1. Locks the submission.
2. Locks the auction.
3. Locks the target deposit or settlement.
4. Validates the submission still matches that obligation.
5. Checks the obligation is not already paid.
6. Locks any existing succeeded transaction for the obligation key.
7. Creates the succeeded transaction with `successful_obligation_key`.
8. Updates the deposit/settlement in the same transaction.

If the database unique key is hit under race, the application returns a clear domain error and rolls back without duplicate audit/state updates.

## 8. Reject Then Resubmit

Rejecting a deposit submission still returns the deposit to `pending_submission`.

The tests prove:

```text
Submission A -> Rejected
Submission B -> Approved
```

This creates two submissions for history and only one successful transaction.

## 9. Created Files

- `app/Services/Auction/Support/FinancialObligationKey.php`
- `database/migrations/2026_07_12_030000_add_payment_successful_obligation_key.php`
- `tests/Feature/Auction/AuctionPaymentUniquenessTest.php`
- `tests/Feature/Auction/AuctionPaymentUniquenessMysqlTest.php`
- `AUCTION_TASK_02_PAYMENT_UNIQUENESS_REPORT.md`

## 10. Modified Files

- `app/Models/Auction/PaymentTransaction.php`
- `app/Repositories/Auction/AuctionPaymentRepository.php`
- `app/Services/Auction/Actions/SubmitPaymentSubmissionAction.php`
- `app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php`
- `database/migrations/2025_06_12_090000_create_auctions_table.php`
- `lang/ar/auction.php`
- `lang/en/auction.php`
- `phpunit.mysql.xml`

## 11. New Migration

```text
2026_07_12_030000_add_payment_successful_obligation_key
```

It was applied with:

```text
php artisan migrate --force
```

No `migrate:fresh` was used.

## 12. New Tests

Feature tests:

- Same submission approved twice creates one transaction and one audit.
- Pending duplicate submission is blocked.
- Reject then resubmit works.
- Two different deposit submissions cannot both be approved.
- Two different settlement submissions cannot both be approved.
- Database unique key rejects duplicate successful obligation key.
- Duplicate provider transaction id is rejected.

MySQL test:

- Two separate seller-deposit submissions for the same deposit are approved concurrently by two worker processes.
- Exactly one process returns `approved`.
- The other returns `already_paid`.
- The database contains exactly one successful transaction for the obligation key.

## 13. Actual Test Results

```text
composer dump-autoload
PASS - final run completed and discovered packages.
```

```text
php artisan optimize:clear
PASS
```

```text
php artisan migrate:status
PASS - 2026_07_12_030000_add_payment_successful_obligation_key is Ran.
```

```text
php artisan test --filter=AuctionPayment
PASS - 6 passed, 1 MySQL-only skipped, 20 assertions.
```

```text
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionPayment
PASS - 7 passed, 28 assertions.
Note: Laravel emitted "Option --configuration cannot be used more than once", but the suite ran and passed.
```

```text
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionMigrationSafety
PASS - 1 passed, 40 assertions.
Note: validates fresh and legacy MySQL migration paths finish with the same auction schema.
```

```text
vendor/bin/pint --test
FAIL - repo-wide Pint reports 148 pre-existing style issues across unrelated files.
```

```text
vendor/bin/pint --test <modified files>
PASS - 12 modified/task files passed.
```

Syntax checks:

```text
PASS - php -l on all modified PHP files.
```

## 14. MySQL Concurrency Result

Dedicated MySQL database:

```text
soom_payment_uniqueness_testing
```

Guard:

```text
Database name must end with _testing.
```

Result:

```text
7 passed, 28 assertions
parallel deposit approval: approved + already_paid
successful transaction count for deposit key: 1
```

## 15. Commands Run

```text
composer dump-autoload
composer dump-autoload --no-dev --no-scripts --no-interaction
php artisan optimize:clear
php artisan migrate:status
php artisan migrate --force
php artisan test --filter=AuctionPayment
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionPayment
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionMigrationSafety
vendor/bin/pint --test
vendor/bin/pint --test <modified files>
php -l <modified PHP files>
```

## 16. Checks That Could Not Fully Pass

Repo-wide Pint did not pass because of unrelated pre-existing style issues in many files outside this task. The files created or modified for this task passed Pint.

The first two `composer dump-autoload` attempts timed out while generating optimized autoload files. After running the lighter no-dev/no-scripts autoload once, the required `composer dump-autoload` command completed successfully.

## 17. Remaining Risks

- Repo-wide style debt remains outside this payment uniqueness task.
- The upgrade migration intentionally fails fast if a production database already contains duplicate successful payments for the same deposit or settlement; those historical duplicates must be resolved before applying the unique constraints.
- This task did not change Refunds, Cancellation, Winner Default, Outbox consumers, Resources, Scheduler concurrency, or Configuration Snapshot flows.
