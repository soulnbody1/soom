# AUCTION_TASK_05_APPLIED_DEPOSIT_REFUND_REPORT

## Scope

Implemented only applied deposit refund accounting and confirmation safety.

Did not implement full refund provider lifecycle, retry/backoff, cancellation policy expansion, or winner default policy expansion.

## Root Cause

`RefundAuctionDepositAction::confirmSucceeded()` validated deposit refunds only against `held_amount_minor`.

That rejected valid refunds when the captured deposit had already moved from:

```text
held_amount_minor -> applied_amount_minor
```

after being applied to a winner settlement, even when that settlement had later been cancelled or invalidated.

The old flow also did not persist a clear allocation on the refund row, so confirmation could not safely know which deposit bucket the refund was supposed to consume.

## Deposit Bucket Flow

Current bucket meanings after this change:

```text
held_amount_minor
```

Captured deposit amount still held and not applied, refunded, or forfeited.

```text
applied_amount_minor
```

Captured deposit amount used to reduce the current winner settlement amount due.

```text
forfeited_amount_minor
```

Captured deposit amount permanently forfeited and not refundable.

```text
refunded_amount_minor
```

Amount actually confirmed as refunded.

```text
required_amount_minor
```

Original required deposit amount. It is not treated as proof of captured funds.

Refundability now uses the successful `PaymentTransaction` for `deposit:{id}` as the captured funds source.

## Implemented Changes

- Added refund allocation columns:
  - `held_refund_amount_minor`
  - `applied_refund_amount_minor`
- Replaced the unique `payment_transaction_id` refund constraint with a normal index so one captured payment can have multiple safe partial refunds, while bucket reservations prevent over-refund.
- Added `DepositRefundAllocation::calculateRefundableDepositAmount()` to calculate refundable held/applied buckets after:
  - successful captured payment check
  - existing pending refund reservations
  - forfeited/refunded amounts
  - related settlement state
- Updated deposit refund creation to persist the allocation and link to the successful deposit `PaymentTransaction`.
- Updated deposit refund confirmation to:
  - lock refund, deposit, source payment, existing deposit refunds, and related settlements
  - reject over-refund
  - reject applied refund while the related settlement is active
  - reject amounts reserved by pending refunds
  - decrement held/applied buckets according to allocation
  - increment refunded amount once
  - mark the source payment `Reversed` only after confirmed refunds for that payment reach the captured payment amount
  - remain idempotent on repeated confirmation
- Updated cancellation refund planning to cancel settlements before planning deposit refunds, so applied deposits become refundable only after settlement cancellation in the same transaction.
- Updated existing tests that created refundable deposit funds without a successful payment transaction.

## Settlement Rule

Applied deposit is refundable only when related applied settlements are not active.

Allowed settlement invalidation states for refund accounting:

```text
cancelled
defaulted
```

Any other related settlement status is treated as still using the applied deposit and blocks the applied allocation.

## Tests

Feature:

```text
php artisan test tests\Feature\Auction\AppliedDepositRefundTest.php
php artisan test tests\Feature\Auction\CancellationRefundTest.php
php artisan test tests\Feature\Auction
```

MySQL:

```text
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='soom_testing'; php artisan test tests\Feature\Auction\AppliedDepositRefundMysqlTest.php --group=mysql-concurrency
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='soom_testing'; php artisan test --group=mysql-concurrency
```

Formatting:

```text
vendor\bin\pint <touched files>
```

Final results:

```text
AppliedDepositRefundTest: 9 passed, 34 assertions
AppliedDepositRefundMysqlTest: 1 passed, 11 assertions
tests\Feature\Auction: 72 passed, 14 skipped, 292 assertions
--group=mysql-concurrency: 13 passed, 66 assertions
Pint touched-files run: passed
```
