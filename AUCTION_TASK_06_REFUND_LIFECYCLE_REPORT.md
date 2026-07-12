# AUCTION_TASK_06_REFUND_LIFECYCLE_REPORT

## Scope

Implemented the auction refund lifecycle only:

```text
Pending -> Processing -> Succeeded
```

with:

```text
Failed
ManualReview
Cancelled
Retry/Backoff
Processing lease
Manual admin confirmation
Audit
Outbox
MySQL concurrency tests
```

No fake provider integration was added. The default processor is an explicit manual-review contract: if no real provider exists, processing moves the refund to `ManualReview` and requires documented admin confirmation.

Not implemented in this task:

```text
Seller deposit final policy
Non-winner release policy
Winner default flow
General cancellation refactor
General outbox consumer implementation
```

## Schema

Added lifecycle fields to `refund_transactions`:

```text
attempt_count
last_error
next_retry_at
processing_started_at
processing_token
lease_expires_at
provider_response
manual_confirmed_by
manual_confirmed_at
manual_confirmation_reason
succeeded_at
failed_at
cancelled_at
cancelled_by
cancellation_reason
```

Existing provider uniqueness remains:

```text
UNIQUE(provider, provider_refund_id)
```

## Lifecycle

Added refund statuses:

```text
processing
manual_review
cancelled
```

Existing `pending`, `succeeded`, and `failed` remain.

Processing claim:

- Locks the refund.
- Allows `Pending`, due `Failed`, or expired `Processing`.
- Rejects active `Processing`, `Succeeded`, `Cancelled`, and `ManualReview`.
- Increments `attempt_count`.
- Stores `processing_token`, `processing_started_at`, and `lease_expires_at`.
- Commits before calling the processor.

Processing completion:

- Re-locks the refund.
- Requires matching `processing_token`.
- Applies success, retryable failure, non-retryable failure, or manual review.
- Clears lease fields on terminal/failure transitions.

## Processor Contract

Added:

```text
AuctionRefundProcessorInterface
RefundProcessingResult
RefundProcessingOutcome
ManualReviewRefundProcessor
```

The default `ManualReviewRefundProcessor` does not pretend to refund money. It returns `ManualReviewRequired`.

## Manual Confirmation

Added `ConfirmAuctionRefundManuallyAction`.

It requires:

```text
auction.refunds.confirm_manual
confirmation_reference
reason
```

It rejects cancelled refunds, active processing refunds, missing reason/reference, and unauthorized users. Repeated confirmation of an already succeeded refund is idempotent.

## Cancellation

Added `CancelAuctionRefundAction`.

It allows:

```text
Pending
Failed
ManualReview
```

It rejects:

```text
Succeeded
Processing
```

Cancellation does not modify payment transactions or deposit buckets.

## Accounting Safety

Refund success now goes through `AuctionRefundCompletion`, shared by provider success and manual confirmation.

It preserves TASK 05 behavior:

- Applies held/applied allocation once.
- Uses the successful source `PaymentTransaction`.
- Blocks duplicate provider references.
- Marks the source payment `Reversed` only after successful refunds reach the captured payment amount.
- Does not update buckets on retryable/non-retryable failure, manual review, or cancellation.

Pending and Processing refunds reserve held/applied allocation. Failed and Cancelled refunds do not.

## Jobs and Scheduling

Added:

```text
ProcessPendingAuctionRefundsJob
```

It uses `lazyById` over due refunds, applies `skipLocked` when the MySQL query builder supports it, and lets `ProcessAuctionRefundAction` enforce DB leases.

Updated:

```text
auction:run-operations
```

to dispatch `ProcessPendingAuctionRefundsJob`.

`php artisan schedule:list` confirms `auction:run-operations` is scheduled every minute.

## Audit and Outbox

Audit events added:

```text
auction.refund_created
auction.refund_processing_started
auction.refund_processing_failed
auction.refund_retry_scheduled
auction.refund_moved_to_manual_review
auction.refund_manually_confirmed
auction.refund_succeeded
auction.refund_cancelled
```

Outbox events added:

```text
auction.refund_processing
auction.refund_failed
auction.refund_manual_review
auction.refund_succeeded
auction.refund_cancelled
```

## Tests

Feature lifecycle:

```text
php artisan test tests\Feature\Auction\AuctionRefundLifecycleTest.php
```

Result:

```text
11 passed, 37 assertions
```

Refund regression tests:

```text
php artisan test tests\Feature\Auction\AppliedDepositRefundTest.php tests\Feature\Auction\CancellationRefundTest.php
```

Result:

```text
14 passed, 61 assertions
```

Required filter:

```text
php artisan test --filter=AuctionRefundLifecycle
```

Result:

```text
11 passed, 2 skipped, 37 assertions
```

Required MySQL filter:

```text
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionRefundLifecycle
```

Result:

```text
13 passed, 51 assertions
```

Laravel printed a warning that `--configuration` cannot be used more than once, but the command exited successfully and ran the MySQL lifecycle tests.

MySQL lifecycle concurrency:

```text
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='soom_testing'; php artisan test tests\Feature\Auction\AuctionRefundLifecycleMysqlTest.php --group=mysql-concurrency
```

Result:

```text
2 passed, 14 assertions
```

Full MySQL concurrency group:

```text
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='soom_testing'; php artisan test --group=mysql-concurrency
```

Result:

```text
15 passed, 80 assertions
```

Auction Feature suite:

```text
php artisan test tests\Feature\Auction
```

Result:

```text
83 passed, 16 skipped, 329 assertions
```

Formatting:

```text
vendor\bin\pint --test <touched files>
```

Result:

```text
24 files passed
```

Other checks:

```text
php artisan optimize:clear
php artisan route:list
php artisan schedule:list
php artisan migrate:status
```

Results:

- `optimize:clear`: passed.
- `route:list`: passed, 183 routes listed.
- `schedule:list`: passed, `auction:run-operations` listed every minute.
- `migrate:status`: passed. The new task migrations are pending in the current default database, and are applied inside the test databases during test runs.

`composer dump-autoload` was attempted twice:

```text
composer dump-autoload
```

It timed out after 120 seconds, then again after 300 seconds, both times while printing:

```text
Generating optimized autoload files
```

No success is claimed for that command.
