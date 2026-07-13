# AUCTION TASK 10 - Central Financial Cancellation Service Report

## 1. Old Cancellation Paths

- `CancelAuctionAction` previously owned cancellation financial effects directly.
- `ResolveAuctionDisputeAction` had a cancel branch that could set auction/settlement status without going through the same full cancellation flow.
- Controller seller/admin cancellation entered through `CancelAuctionAction`.
- System-triggered cancellation in existing tests used the same action style, but there was no typed cancellation context.
- Jobs currently do not mutate `Auction.status = Cancelled` directly.

## 2. Problems Found

- Cancellation reason, trigger, actor, and liability were string-driven.
- Dispute cancellation was a separate path and risked status-only cancellation.
- There was no persisted cancellation operation marker.
- Current settlement closure did not have cancellation-specific historical fields.
- Reconciliation did not identify cancelled auctions with incomplete financial cleanup.

## 3. New Central Design

Created one orchestrator:

```text
app/Services/Auction/Actions/CancelAuctionFinanciallyAction.php
```

All normal seller/admin/system/dispute cancellation entry points delegate to this service. It locks the auction, current settlement, deposits, payment submissions, successful payments, and existing refunds before applying the cancellation plan.

## 4. Cancellation Triggers

Created:

```text
app/Domain/Auction/Enums/AuctionCancellationTrigger.php
```

Supported triggers:

- `seller_requested`
- `admin_requested`
- `system_triggered`
- `dispute_resolved`
- `compliance`
- `fraud`
- `platform_fault`
- `seller_breach`
- `buyer_fault`
- `neutral_administrative`

## 5. Cancellation Context

Created:

```text
app/DTO/Auction/AuctionCancellationContextDTO.php
```

It carries auction id, trigger, actor, reason code/text, liability, dispute id, requested time, and metadata. It also provides the stable operation key:

```text
auction:{auctionId}:cancel
```

## 6. Financial Plan

Created:

```text
app/DTO/Auction/AuctionCancellationPlanDTO.php
```

The plan records current settlement, current winner, pending submission count, successful winner-payment count, successful bidder-deposit payment count, seller deposit disposition, and manual-review flags.

## 7. Seller Deposit Integration

Cancellation uses TASK 08 via:

```text
ResolveSellerDepositDispositionAction
SellerDepositDispositionResolver
```

The central action passes cancellation-specific fault and policy keys:

- platform fault
- seller fault
- fraud/compliance
- neutral
- system cancellation variants

It does not implement a parallel seller deposit refund/forfeit flow.

## 8. Bidder Deposit Integration

Cancellation uses TASK 07 via:

```text
PlanNonWinnerDepositRefundsAction
```

Current winner bidder deposit is handled separately so it is not treated as an ordinary non-winner. Non-winner deposits are then released through the existing non-winner release flow.

## 9. Winner Payment Integration

Successful winner settlement payments create refund plans from the original `PaymentTransaction`.

Payment transactions remain `succeeded` until the refund lifecycle later confirms success. No settlement field is used as refund source of truth.

## 10. Applied Deposit Handling

The current settlement is closed as cancelled before the winner deposit is refunded. This allows TASK 05 applied-deposit refund accounting to calculate refundable applied amounts through `RefundAuctionDepositAction`.

## 11. Settlement Handling

Added historical cancellation fields to settlements:

```text
cancelled_at
cancelled_by
cancel_reason
```

`AuctionSettlementRepository::closeAsCancelled()` sets:

- `status = cancelled`
- `is_current = false`
- `current_marker = null`
- `superseded_at`

It preserves the settlement's financial history.

## 12. Payment Submission Handling

Added:

```text
AuctionPaymentRepository::lockPendingReviewSubmissionsForAuction()
```

Pending review submissions are marked rejected with:

```text
review_note = auction_cancelled
```

Approved historical submissions are preserved.

## 13. Dispute Cancellation Integration

`ResolveAuctionDisputeAction` now sends `resolution = cancel` through `CancelAuctionFinanciallyAction` with:

- trigger `DisputeResolved`
- admin resolver
- dispute id
- note as reason text
- liability derived from seller deposit disposition

The old status-only cancel branch is no longer used.

## 14. System Cancellation Integration

System cancellation is represented by `AuctionCancellationTrigger::SystemTriggered`. Existing action callers can pass `actorType = system` through the same central action/wrapper, and seller deposit disposition receives system-specific policy keys.

## 15. Manual Review Cases

Manual review is recorded when:

- seller deposit resolver returns `ManualReview`
- cancellation liability is `manual_review`

The auction stores:

```text
financial_cancellation_manual_review_required
```

## 16. Idempotency Design

- The auction row is locked first.
- Already cancelled auctions return idempotently without creating a new financial plan.
- The operation key is stable and persisted.
- Existing active/succeeded refund checks prevent duplicate refund planning.
- Seller deposit disposition action remains idempotent.
- Replays do not duplicate central cancellation audit events.

## 17. Concurrency Protection

MySQL concurrency is protected by the auction row lock plus locked deposits/payments/refunds. The first transaction completes the financial cancellation; the second sees `Cancelled` and returns the current result.

## 18. Actions and Rules Created or Modified

Created:

- `CancelAuctionFinanciallyAction`
- `AuctionCancellationTrigger`
- `AuctionCancellationContextDTO`
- `AuctionCancellationPlanDTO`

Modified:

- `CancelAuctionAction`
- `ResolveAuctionDisputeAction`
- `ReconcileAuctionsAction`
- `AuctionController`
- `AuctionPolicy`
- `config/auction.php`

Created request:

- `CancelAuctionRequest`

## 19. Repositories Modified

- `AuctionPaymentRepository::lockPendingReviewSubmissionsForAuction()`
- `AuctionPaymentRepository::lockSucceededTransactionsForAuction()`
- `AuctionSettlementRepository::closeAsCancelled()`

## 20. Permissions

Added clearer admin permissions:

```text
auction.cancel.admin
auction.cancel.compliance
```

The legacy `auction.cancel` is retained for compatibility. Seller cancellation remains ownership and state based.

`CancelAuctionRequest` requires admin callers to provide:

- `reason_code`
- `reason_text`
- `liability`

## 21. Audit Events

Central audit:

- `auction.cancellation_started`
- `auction.cancellation_financial_plan_created`

Specialized financial actions continue to emit their own refund/forfeit audit events.

## 22. Outbox Events

Central outbox:

- `auction.cancellation_started`
- `auction.cancellation_financial_plan_created`
- `auction.cancelled`

Specialized financial actions continue to emit their own refund/forfeit outbox events.

## 23. Reconciliation Checks

`ReconcileAuctionsAction` now reports:

- `cancelled_auctions_financially_unbalanced`
- `cancelled_current_settlements_active`
- `cancelled_pending_payment_submissions`
- `cancelled_successful_payments_without_refund_or_disposition`
- `cancelled_bidder_deposits_held`
- `cancelled_seller_deposits_without_disposition`

Reconciliation reports only; it does not apply financial fixes.

## 24. New Tests

Created:

- `tests/Feature/Auction/AuctionCancellationTest.php`
- `tests/Feature/Auction/AuctionCancellationMysqlTest.php`

Covered:

- Seller/admin/system cancellation paths use the central service.
- Explicit admin `reason_code` and `liability` drive cancellation context.
- Dispute cancellation uses the central service.
- Current settlement is historically closed.
- Pending submissions are superseded.
- Winner deposit and winner payment refunds use source payments.
- Payment transactions remain succeeded until refund success.
- Completed auctions reject normal cancellation.
- Replays are idempotent.
- MySQL concurrent seller/admin cancellation creates one financial plan.
- Reconciliation detects unbalanced cancelled auctions.

## 25. Feature Test Results

```text
php artisan test --filter=AuctionCancellation
Result: PASS - 7 passed, 1 MySQL-only skipped, 40 assertions.
```

Related regression checks:

```text
php artisan test --filter=CancellationRefund
Result: PASS - 5 passed, 1 MySQL-only skipped, 27 assertions.

php artisan test --filter=SellerDeposit
Result: PASS - 13 passed, 1 MySQL-only skipped, 45 assertions.

php artisan test --filter=NonWinnerDeposit
Result: PASS - 11 passed, 1 MySQL-only skipped, 52 assertions.
```

## 26. MySQL Concurrency Test Results

```text
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionCancellation
Result: PASS - 8 passed, 52 assertions.
Note: Laravel warned that --configuration cannot be used more than once, but the MySQL suite executed and passed.
```

## 27. Commands Run

```text
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan migrate:status
php artisan test --filter=AuctionCancellation
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionCancellation
php artisan test --filter=CancellationRefund
php artisan test --filter=SellerDeposit
php artisan test --filter=NonWinnerDeposit
vendor/bin/pint --test
vendor/bin/pint <TASK 10 files>
vendor/bin/pint --test <TASK 10 files>
php -l <TASK 10 PHP files>
rg status-only cancellation searches
```

## 28. Checks That Could Not Fully Pass

- Full `vendor/bin/pint --test` failed because the repository currently has broad pre-existing style issues outside TASK 10: 151 style issues across 414 files.
- Scoped Pint for TASK 10 files passed after formatting.
- `php artisan migrate:status` shows two pending migrations in the current local database:
  - `2026_07_13_030000_harden_winner_default_flow`
  - `2026_07_13_050000_add_central_cancellation_tracking`

The test suites run migrations in their own test databases and passed.

## 29. Deferred Risks To TASK 11

- Configuration Snapshot remains existing partial snapshot/config behavior.
- General Outbox consumers remain deferred.
- No new refund provider lifecycle was introduced.
- No full seller deposit policy redesign was introduced.
- No full winner default redesign was introduced.
