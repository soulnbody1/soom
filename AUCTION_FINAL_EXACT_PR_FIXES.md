# FINAL AUCTION PR FIXES — Exact Scope Only

## Project

```text
C:\Users\pc\Desktop\SB\soom
```

## Objective

Fix the remaining Auction PR blockers found in the final review, perform the confirmed dead-code cleanup, and verify PR readiness.

This is a focused patch.

Do not redesign the Auction system and do not add new architecture.

---

# 1. Fix unsupported `auction.status_changed` Outbox messages

Review:

```text
app/Services/Auction/Support/AuctionStateMachine.php
app/Services/Auction/Support/AuctionAudit.php
app/Services/Auction/Actions/DispatchOutboxMessagesAction.php
```

## Current problem

`AuctionStateMachine` creates:

```text
auction.status_changed
```

for every status transition.

However, `DispatchOutboxMessagesAction` only supports notifications for transitions to:

```text
Scheduled
Live
Ended
```

Transitions to statuses such as:

```text
PendingReview
Rejected
AwaitingSellerDeposit
Unsold
SettlementPending
PaymentPending
HandoverPending
Defaulted
Disputed
Completed
Cancelled
```

currently create Outbox messages that later fail with:

```text
Unsupported status_changed target
```

## Required fix

Continue recording **all status transitions** in the Audit Log.

Create the Outbox notification:

```text
auction.status_changed
```

only when the new Auction status is:

```text
Scheduled
Live
Ended
```

Do not add handlers for every other status.

Do not remove Audit logging for the other transitions.

Add or update a small test proving that transitions to statuses such as:

```text
PaymentPending
Completed
Cancelled
```

do not create unsupported Outbox messages.

---

# 2. Register Broadcast routes only once

Review:

```text
bootstrap/app.php
app/Providers/BroadcastServiceProvider.php
app/Providers/AppServiceProvider.php
routes/channels.php
```

## Current problem

Broadcast routes and `routes/channels.php` are currently registered or loaded from multiple places.

## Required fix

Use only the modern Laravel registration already present in:

```text
bootstrap/app.php -> withBroadcasting()
```

Remove duplicate registration, including where applicable:

```text
Broadcast::routes()
require base_path('routes/channels.php')
BroadcastServiceProvider registration
```

If `BroadcastServiceProvider` becomes completely unused, delete the provider file.

Do not change the required route prefix or authentication middleware beyond removing the duplicate registrations.

Verify that the Broadcast authentication route appears exactly once in:

```bash
php artisan route:list
```

---

# 3. Add Reserve Amount validation

Review:

```text
app/Http/Requests/Auction/StoreAuctionRequest.php
```

## Required behavior

When `reserve_amount` is provided:

```text
reserve_amount must be greater than or equal to starting_amount
```

Invalid input must return:

```text
HTTP 422 Validation Error
```

It must not reach the database constraint and produce:

```text
QueryException
HTTP 500
```

Use the existing money parsing or conversion logic already used by the Auction module.

Do not create a new Validation Rule class, Service, DTO, or helper for this.

Use the existing FormRequest validation hooks.

Add one focused test for:

```text
reserve_amount < starting_amount
```

and assert a `422` response with an error on `reserve_amount`.

---

# 4. Keep `RefundPendingAuctionDepositsJob`

Do **not** delete:

```text
app/Jobs/Auction/RefundPendingAuctionDepositsJob.php
```

The Job may be required when:

```text
a deposit has held and applied amounts
the held part is refunded first
the applied part is temporarily blocked by an active settlement
the applied part becomes refundable later
```

Keep the Job and its scheduling.

Do not replace it with a new Job.

---

# 5. Clean `ProcessPendingAuctionRefundsJob`

Review:

```text
app/Jobs/Auction/ProcessPendingAuctionRefundsJob.php
```

If the query currently uses:

```php
lockForUpdate()
skipLocked()
```

without a database transaction covering the query iteration, remove those misleading locks.

The actual concurrency protection should remain the existing:

```text
processing token
lease expiration
transaction inside ProcessAuctionRefundAction
```

Do not change the Refund lifecycle.

Do not leave an empty or silent catch such as:

```php
catch (AuctionException) {
}
```

The batch may continue processing, but unexpected failures must be logged using the existing Laravel logger with useful context:

```text
refund id
auction id when available
exception message
```

Do not add a logging Service.

---

# 6. Remove confirmed unused Repository methods

Perform a full-project search before deleting anything.

Review the following candidates:

```text
AuctionBidRepository::lockNextHighestBidExcluding
AuctionConfigurationRepository::findByPublicId
AuctionDepositRepository::markNonWinnerDepositsRefundPending
AuctionDepositRepository::lockRefundableForCancellation
AuctionRepository::findByPublicId
AuctionRepository::findByPublicIdForSeller
AuctionRepository::setLeadingBid
AuctionRepository::findAuctionsNeedingDepositRefund
AuctionSettlementRepository::findByAuctionAndWinner
AuctionSettlementRepository::lockByAuctionAndWinner
AuctionSettlementRepository::lockCancellableForAuction
```

Delete a method only when there is no:

```text
direct runtime call
dynamic call
interface requirement
route usage
job usage
command usage
action usage
policy usage
test usage that represents real behavior
```

When a method is declared in an interface and implementation, remove it consistently from both.

Do not merge Repositories.

Do not introduce a Generic Repository.

Do not change active Repository behavior.

If any candidate is actually used, keep it and mention its caller in the final response.

---

# 7. Remove unused Auction configuration

Review:

```text
config/auction.php
```

Check:

```text
auction.refunds.auto_succeed_manual_refunds
```

If there is no runtime code reading this key, delete it.

Do not rename, reorganize, or change any active Auction configuration key.

---

# 8. Fix the remaining Snapshot schema inconsistencies

Review the existing Auction Configuration Snapshot creation migration and validator.

Do not create a new migration because the Auction system has not been released.

## Alternative candidate limit

Required behavior:

```text
alternative_winner_enabled = false
→ alternative_candidate_limit may be 0

alternative_winner_enabled = true
→ alternative_candidate_limit must be greater than 0
```

Update the existing validator and database check constraint consistently.

Do not change the alternative-winner Business Flow.

## Platform fee value

If:

```text
platform_fee_value
```

may store a fixed fee in Minor Units, change its column type in the existing creation migration from:

```text
unsignedInteger
```

to:

```text
unsignedBigInteger
```

Keep the current calculation and meaning unchanged.

---

# 9. Protect MySQL Auction tests

Review:

```text
tests/Feature/Auction/AuctionMysqlConcurrencyTest.php
phpunit.mysql.xml
```

Before running migrations or destructive database test preparation, verify:

```text
database driver is mysql
database name ends with _testing
```

If the database name does not end with:

```text
_testing
```

the test must stop immediately with a clear error or skipped-test message before executing migrations or destructive commands.

Use a simple guard inside the existing test setup.

Do not create a new testing framework or Base Test class unless one already exists and is already used.

---

# 10. Add explicit Auction test scripts

Review:

```text
composer.json
```

Add these Composer scripts if they do not already exist:

```json
"test:auction": "@php artisan test tests/Feature/Auction tests/Unit/Auction",
"test:auction:mysql": "@php artisan test --configuration=phpunit.mysql.xml"
```

Do not change existing dependencies or unrelated Composer scripts.

---

# 11. Remove temporary files from the PR

Delete temporary Agent prompts, execution reports, and cache files that should not be part of the final PR, including:

```text
AUCTION_CURRENT_SYSTEM_AUDIT.md
AUCTION_FIX_MANUAL_REFUND_FLOW.md
AUCTION_PRODUCTION_GRADE_REFACTOR.md
AUCTION_REMAINING_REQUIREMENTS_MASTER.md
AUCTION_TASK_*.md
AUCTION_TASK_*_REPORT.md
.phpunit.result.cache
```

Also confirm that generated runtime files are not tracked, including:

```text
storage/logs/laravel.log
bootstrap/cache/packages.php
bootstrap/cache/services.php
```

Do not delete required framework directories.

Keep the useful final Auction documentation, including:

```text
AUCTION_API_DOCUMENTATION.md
AUCTION_ASSUMPTIONS_AND_POLICIES.md
AUCTION_DATABASE_DESIGN.md
AUCTION_DEPLOYMENT_AND_OPERATIONS.md
AUCTION_ERD.md
AUCTION_FULL_FLOW_AR.md
AUCTION_SECURITY_REVIEW.md
AUCTION_STATE_MACHINES.md
AUCTION_TARGET_ARCHITECTURE.md
README.md
```

Do not rewrite these documents.

---

# Strict restrictions

Do not:

```text
Split large Actions
Rewrite MarkWinnerDefaultedAction
Rename classes or methods
Redesign the architecture
Add Services
Add Actions
Add DTOs
Add Interfaces
Add Repositories
Add Traits
Add Events
Add Listeners
Add Jobs
Add design patterns
Add new migrations
Change API contracts
Change response structures
Change financial calculations
Change the Auction state machine outside the Outbox condition
Change permissions behavior
Optimize unrelated queries
Modify frontend code
Perform a general refactor
Create a new report file
```

Modify existing files only wherever possible.

The only allowed new code is the smallest validation or test code required by the points above.

---

# Verification

After completing the changes, run:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan schedule:list
vendor/bin/pint --dirty
composer test:auction
composer test:auction:mysql
```

On a dedicated testing database whose name ends with:

```text
_testing
```

run:

```bash
php artisan migrate:fresh --env=testing
php artisan migrate:rollback --env=testing
php artisan migrate --env=testing
```

Never run destructive migration commands on development, staging, or production databases.

Run PHP syntax validation for every modified PHP file.

Inspect the final Git diff and confirm that no unrelated files were changed.

---

# Required final checks

Confirm all of the following:

```text
No unsupported status_changed Outbox messages are created
Broadcast authentication route is registered once
reserve_amount below starting_amount returns 422
RefundPendingAuctionDepositsJob remains available
Refund processing failures are not silently swallowed
No deleted Repository method still has a caller
No unused auto_succeed_manual_refunds config remains
Alternative candidate limit validation is consistent
platform_fee_value uses a safe integer type
MySQL tests refuse non-testing databases
No duplicate schedules exist
No Class not found errors exist
No broken imports exist
Auction tests pass
MySQL concurrency tests pass
Fresh MySQL migrations pass
Rollback and re-run pass
Pint passes
```

---

# Final response

Do not create a report file.

In your final response, provide:

```text
1. Files modified.
2. Files deleted.
3. Exact fix applied to status_changed Outbox handling.
4. Broadcast registrations removed and the single retained registration.
5. Reserve validation implementation and test result.
6. Changes made to the Refund processing Job.
7. Repository methods and config keys deleted.
8. Snapshot schema corrections.
9. MySQL safety guard implementation.
10. Exact results of route:list and schedule:list.
11. Exact Pint and test results.
12. Exact migration verification results.
13. Any remaining PR blocker.
14. Final verdict: Ready for PR or Not Ready for PR.
```

Do not stop after analysis or planning.

Implement the changes, run the verification, inspect the Git diff, and then provide the final result.
