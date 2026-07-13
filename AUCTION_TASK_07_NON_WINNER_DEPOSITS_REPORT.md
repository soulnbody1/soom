# AUCTION TASK 07 - Non-Winner Bidder Deposit Release Report

## 1. Old Flow

- `FinalizeAuctionAction` used `AuctionDepositRepository::markNonWinnerDepositsRefundPending()` for some non-winner deposits.
- That changed deposit state to `refund_pending` without always creating a `refund_transactions` plan.
- Winner payment approval moved the auction to `handover_pending`, but there was no unified cleanup that released held non-winner bidder deposits.
- Winner default reassignment selected a new winner, but did not consistently reclassify the remaining held candidates.
- Cancellation created refund plans from successful payment transactions, but unpaid held deposits could still need non-monetary cleanup.

## 2. Root Cause

The root issue was a split between deposit state changes and the refund source of truth. Some code marked deposits as pending refund directly, while TASK 04/TASK 06 made `refund_transactions` with source `payment_transaction_id` the real refund plan/lifecycle.

## 3. Final Retention Policy

Implemented policy reading from auction configuration snapshot first, then `config('auction')` fallback:

- `refund_all_non_winners_immediately`: no alternative candidates are retained at finalization.
- `hold_all_eligible_bidders_until_winner_payment`: eligible non-winners stay held with hold metadata until winner payment or terminal cleanup.
- `hold_top_n_bidders_until_winner_payment`: top N eligible non-winner candidates stay held. N comes from snapshot keys or `auction.non_winner_deposit_hold_count`.

## 4. Current Winner And Alternative Candidates

- Current winner is detected from current settlement winner and auction winning bid.
- Current winner deposit is excluded from non-winner release.
- Defaulted winner user ids passed by `MarkWinnerDefaultedAction` are excluded from normal non-winner release and left for the later default lifecycle.
- Alternative candidates must be qualified, have held bidder deposit, have accepted terms, and have no active/succeeded refund.

## 5. Refund Plan Triggers

Refund planning now runs through `PlanNonWinnerDepositRefundsAction` on:

- Finalization.
- Winner payment approval.
- Winner default with alternative selected.
- Winner default without alternative.
- Unsold.
- Completed.
- Cancelled cleanup after the existing cancellation refund flow.

## 6. Case Handling

- Finalization: applies policy; either plans refunds or stores `hold_reason=alternative_winner_candidate`.
- Winner payment: releases all non-current-winner bidder deposits that still have captured refundable money.
- Winner default: keeps the new current winner and policy-valid next candidates; excludes the defaulted winner.
- Unsold: releases all paid bidder deposits; unpaid obligations are closed without monetary refund.
- Completed: terminal cleanup releases stale non-winner deposits.
- Cancelled: does not create a parallel cancellation flow; it skips deposits already covered by active/succeeded refunds.

## 7. Idempotency Design

- Auction, deposits, ranked bids, refunds, and payment transactions are locked inside the action.
- Refund creation still goes through `RefundAuctionDepositAction`.
- Existing active/succeeded refunds are locked and checked before planning.
- Re-running the action does not create duplicate refunds, duplicate audit events, or duplicate outbox events.

## 8. Reconciliation Rule

`ReconcileAuctionsAction` now reports:

```text
terminal_non_winner_deposits_held_without_active_need
```

It detects terminal auctions with bidder deposits still `held`, excluding the current winning bid/current settlement winner.

## 9. Actions Created Or Modified

- Created `app/Services/Auction/Actions/PlanNonWinnerDepositRefundsAction.php`.
- Modified:
  - `FinalizeAuctionAction`
  - `ReviewPaymentSubmissionAction`
  - `MarkWinnerDefaultedAction`
  - `CancelAuctionAction`
  - `ConfirmAuctionReceiptByWinnerAction`
  - `ReconcileAuctionsAction`

## 10. DTOs Created

- `DepositHoldDecisionDTO`
- `NonWinnerDepositDispositionDTO`

## 11. Repositories Modified

- `AuctionDepositRepository::lockBidderDepositsForAuction()`
- `AuctionBidRepository::lockRankedBids()`

## 12. Audit Events

- `auction.non_winner_deposit_held`
- `auction.non_winner_deposit_refund_planned`
- `auction.non_winner_deposit_released_without_payment`

Existing refund lifecycle audit events from TASK 06 remain in use.

## 13. Outbox Events

- `auction.non_winner_deposit_refund_planned`
- `auction.non_winner_deposit_released`

Existing refund lifecycle outbox events remain in use.

## 14. New Tests

- `tests/Feature/Auction/NonWinnerDepositReleaseTest.php`
- `tests/Feature/Auction/NonWinnerDepositReleaseMysqlTest.php`

Covered:

- Immediate refund policy.
- Hold all until winner payment.
- Hold top N.
- Winner default with alternative.
- Winner default without alternative.
- Unsold.
- Completed cleanup.
- Cancelled duplicate prevention.
- Idempotency.
- Active refund skip.
- Unpaid deposit no monetary refund.
- Reconciliation detection.
- MySQL concurrent release.

## 15. Feature Test Results

```text
php artisan test --filter=NonWinnerDeposit
Result: PASS - 11 passed, 1 MySQL-only skipped on default non-MySQL connection, 52 assertions.
```

## 16. MySQL Concurrency Test Results

```text
php artisan test --configuration=phpunit.mysql.xml --filter=NonWinnerDeposit
Result: PASS - 12 passed, 62 assertions.
Note: Laravel warned that --configuration cannot be used more than once, but the MySQL test suite executed and passed.
```

## 17. Commands Run

```text
composer dump-autoload
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=NonWinnerDeposit
php artisan test --configuration=phpunit.mysql.xml --filter=NonWinnerDeposit
vendor/bin/pint --test
vendor/bin/pint --test app/Services/Auction/Actions/PlanNonWinnerDepositRefundsAction.php app/DTO/Auction/DepositHoldDecisionDTO.php app/DTO/Auction/NonWinnerDepositDispositionDTO.php tests/Feature/Auction/NonWinnerDepositReleaseTest.php tests/Feature/Auction/NonWinnerDepositReleaseMysqlTest.php
php -l app/Services/Auction/Actions/PlanNonWinnerDepositRefundsAction.php
php -l tests/Feature/Auction/NonWinnerDepositReleaseTest.php
php -l tests/Feature/Auction/NonWinnerDepositReleaseMysqlTest.php
php -l app/DTO/Auction/DepositHoldDecisionDTO.php
php -l app/DTO/Auction/NonWinnerDepositDispositionDTO.php
php -l app/Services/Auction/Actions/FinalizeAuctionAction.php
php -l app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php
php -l app/Services/Auction/Actions/MarkWinnerDefaultedAction.php
php -l app/Services/Auction/Actions/ConfirmAuctionReceiptByWinnerAction.php
php -l app/Services/Auction/Actions/ReconcileAuctionsAction.php
```

## 18. Checks That Could Not Fully Run

- Full `vendor/bin/pint --test` failed because the repository currently has broad pre-existing style issues outside TASK 07: 151 style issues across 414 files.
- Scoped Pint for TASK 07 files passed.

## 19. Deferred Risks

- Defaulted winner financial treatment remains intentionally deferred to TASK 09.
- Seller deposit lifecycle remains untouched.
- Configuration snapshot design remains the existing partial snapshot/config fallback, pending TASK 11.
- Outbox consumers remain pending TASK 13.
