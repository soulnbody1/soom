# AUCTION TASK 08 - Seller Deposit Lifecycle Report

## 1. Old Flow

- Auction approval already skipped `AwaitingSellerDeposit` when seller deposit amount was zero.
- Seller deposit payment approval moved the deposit to `held` and auction to `scheduled`.
- Cancellation used one generic refund plan for all successful payments, so seller deposit was refunded the same way regardless of seller fault, platform fault, or neutral cancellation.
- Unsold, completed, winner default, seller breach, and dispute resolution did not have one central seller deposit disposition rule.

## 2. Root Cause

Seller deposit outcomes were mixed into generic payment/refund behavior. The code had no central policy resolver to distinguish seller fault, platform fault, neutral outcome, buyer/winner fault, or manual review cases.

## 3. Final Seller Deposit States

- Zero required: no seller deposit payment flow.
- Pending/unpaid rejected auction: obligation closes without refund.
- Paid and scheduled: deposit stays `held`.
- Refundable terminal outcomes: deposit becomes `refund_pending` with a `refund_transactions` plan.
- Forfeiture outcomes: `held_amount_minor` is reduced and `forfeited_amount_minor` is increased.
- Manual review / winner default keep-held: deposit remains `held` with `hold_reason` and metadata.

## 4. Trigger Policy

Implemented central `SellerDepositDispositionResolver` with snapshot-first policy lookup and config fallback.

Default behavior:

- `auction_rejected`: refund if paid, close without refund if unpaid.
- `unsold`: refund.
- `completed`: refund.
- seller cancellation before start: refund.
- seller cancellation after start: manual review.
- admin/platform fault: refund.
- admin/seller fault: forfeit.
- admin fraud/compliance: manual review.
- winner default: keep held.
- seller breach: forfeit.
- dispute complete: refund.
- dispute resume handover: keep held.
- dispute cancel: manual review unless explicit disposition is provided.

## 5. Zero Deposit Flow

`ReviewAuctionAction` still transitions directly to `scheduled` when `seller_deposit_amount_minor = 0`. No deposit row or payment submission is created.

## 6. Payment Reject/Resubmit Flow

Existing payment submission flow is preserved:

```text
submit -> pending_review
reject -> deposit pending_submission
resubmit -> pending_review
approve -> held + scheduled
```

The new tests prove one successful payment transaction for the seller deposit obligation.

## 7. Refund Flow

Refundable seller deposits use `RefundAuctionDepositAction`, which preserves TASK 04/TASK 06 source/lifecycle behavior:

- source `PaymentTransaction`
- `refund_transactions.status = pending`
- deposit remains `refund_pending`
- no direct `refunded` state before refund success

## 8. Forfeiture Flow

Forfeiture is applied in `ResolveSellerDepositDispositionAction` under transaction locks:

- locks auction and seller deposit
- locks source payment
- computes available forfeitable amount
- reduces `held_amount_minor`
- increases `forfeited_amount_minor`
- writes audit/outbox once

## 9. Partial Forfeiture

Partial forfeiture is supported when policy/config explicitly asks for it and supplies an amount.

Example tested:

```text
captured = 10000
forfeited = 3000
refund = 7000
```

The forfeited portion is not refundable; only the remaining held amount is planned for refund.

## 10. Idempotency Design

- Action locks auction, seller deposit, source payment, and active/succeeded refunds.
- Refund planning relies on `RefundAuctionDepositAction` idempotency and active refund reservations.
- Forfeiture only applies remaining target amount.
- Manual-review/keep-held events are not duplicated when metadata is unchanged.
- Audit/outbox are emitted only when a financial state actually changes or a refund is newly created.

## 11. Reconciliation Rule

`ReconcileAuctionsAction` now reports:

```text
terminal_seller_deposits_held_without_active_need
seller_deposits_resolved_without_terminal_or_policy_reason
```

Reconciliation detects stale held seller deposits but does not apply financial decisions automatically.

## 12. Actions And Resolvers Created

- `ResolveSellerDepositDispositionAction`
- `SellerDepositDispositionResolver`

Modified actions:

- `ReviewAuctionAction`
- `ReviewPaymentSubmissionAction`
- `FinalizeAuctionAction`
- `CancelAuctionAction`
- `ConfirmAuctionReceiptByWinnerAction`
- `MarkWinnerDefaultedAction`
- `ResolveAuctionDisputeAction`
- `ReconcileAuctionsAction`

## 13. DTOs Created

- `SellerDepositDispositionDTO`

## 14. Repositories Modified

- `AuctionDepositRepository::lockSellerDepositForAuction()`

## 15. Audit Events

- `auction.seller_deposit_payment_approved`
- `auction.seller_deposit_held`
- `auction.seller_deposit_refund_planned`
- `auction.seller_deposit_forfeited`
- `auction.seller_deposit_partially_forfeited`
- `auction.seller_deposit_closed_without_payment`
- `auction.seller_deposit_manual_review_required`

## 16. Outbox Events

- `auction.seller_deposit_held`
- `auction.seller_deposit_refund_planned`
- `auction.seller_deposit_forfeited`
- `auction.seller_deposit_partially_forfeited`
- `auction.seller_deposit_manual_review`

Consumers remain deferred to TASK 13.

## 17. New Tests

- `tests/Feature/Auction/SellerDepositLifecycleTest.php`
- `tests/Feature/Auction/SellerDepositLifecycleMysqlTest.php`

Covered:

- zero seller deposit
- rejected auction before payment
- reject/resubmit/approve payment
- unsold refund
- completed refund
- seller cancellation before and after start
- admin platform fault
- admin seller fault
- system platform fault
- system seller fault
- winner default keep-held
- seller breach forfeiture
- partial forfeiture
- dispute resolution explicit disposition
- idempotency
- reconciliation
- MySQL concurrent resolution

## 18. Feature Test Results

```text
php artisan test --filter=SellerDeposit
Result: PASS - 13 passed, 1 MySQL-only skipped on default non-MySQL connection.
```

## 19. MySQL Concurrency Test Results

```text
php artisan test --configuration=phpunit.mysql.xml --filter=SellerDeposit
Result: PASS - 14 passed, 56 assertions.
Note: Laravel warned that --configuration cannot be used more than once, but the MySQL test suite executed and passed.
```

## 20. Commands Run

```text
composer dump-autoload
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=SellerDeposit
php artisan test --configuration=phpunit.mysql.xml --filter=SellerDeposit
vendor/bin/pint --test
vendor/bin/pint --test <modified files>
php -l <each modified PHP file>
```

## 21. Checks That Could Not Fully Run

- `composer dump-autoload` timed out after 240 seconds while printing `Generating optimized autoload files`.
- Full `vendor/bin/pint --test` failed because the repository has unrelated pre-existing style issues. Pint on the modified files passed.

## 22. Deferred Risks

- Full winner default financial policy remains deferred to TASK 09.
- General cancellation redesign remains deferred.
- Full configuration snapshot refactor remains deferred to TASK 11.
- Outbox consumers remain deferred to TASK 13.
