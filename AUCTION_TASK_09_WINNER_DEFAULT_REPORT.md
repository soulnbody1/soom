# AUCTION TASK 09 - Winner Default Report

## 1. Old Flow

`MarkWinnerDefaultedAction` used to rely mostly on auction/settlement state by convention. It allowed missing `payment_due_at`, did not require a dedicated override permission/reason, kept the defaulted winner deposit applied in some paths, did not supersede old pending winner payment submissions, and could leave the auction in `Defaulted` when no alternative was selected.

## 2. Root Causes

- Winner default eligibility was not checked against the full current unpaid settlement contract.
- Deadline override reused broad dispute permissions.
- Old settlement closure and old payment submissions were not treated as historical/superseded records.
- Defaulted winner deposit disposition did not process `applied_amount_minor`.
- Alternative selection did not explicitly exclude prior defaulted users or non-held/refunded deposits.

## 3. Default Eligibility

Default is now allowed only when:

- `auction.status = payment_pending`.
- A current settlement exists with `is_current = true` and `current_marker = 1`.
- Settlement status is `payment_pending`.
- `amount_due_minor > 0`, `amount_paid_minor < amount_due_minor`, and `remaining_amount_minor > 0`.
- `auction.winning_bid_id` matches settlement winning bid and that bid belongs to settlement winner.
- No succeeded payment transaction exists for the settlement obligation key.

## 4. Deadline And Override

- `payment_due_at = null` is rejected.
- Normal default requires `now > payment_due_at`.
- Override requires `override_deadline = true`, `override_reason`, and `auction.winners.override_payment_deadline`.
- Override metadata is saved on the old settlement: `overridden_by`, `overridden_at`, `override_reason`, `original_payment_due_at`.

## 5. Old Settlement Closure

Added `AuctionSettlementRepository::closeAsHistorical()`. Old settlement is closed as:

- `status = defaulted`
- `is_current = false`
- `current_marker = null`
- `defaulted_at`, `default_reason`, `superseded_at`

Financial fields are left as historical values.

## 6. Winner Deposit Disposition

Added `WinnerDefaultDepositDispositionResolver` with policy from snapshot/config:

- `winner_default_deposit_policy.disposition`
- fallback config `auction.winner_default_deposit_policy.disposition`

Supported dispositions:

- `full_forfeit`
- `partial_forfeit`
- `refund`
- `manual_review`
- `no_action`

Default is `full_forfeit`, moving held/applied buckets to `forfeited_amount_minor` without creating a refund. Refund/partial refund uses existing `RefundAuctionDepositAction`.

## 7. Alternative Candidate Eligibility

Alternative selection now excludes:

- The defaulted bidder user entirely.
- Any user previously defaulted in the same auction.
- Bids without `accepted_at`.
- Non-qualified participants.
- Missing terms acceptance for the auction terms version.
- Deposits that are not `held` or do not meet required amount.

## 8. Alternative Winner Selection

Candidates remain ordered by bid amount descending and sequence ascending, but now pass eligibility checks before selection.

## 9. New Settlement Creation

Alternative winner gets a new settlement via existing `createSettlement()`, with:

- New sequence.
- New winner and bid.
- New deadline.
- `amount_paid_minor = 0`.
- `previous_settlement_id`.
- `winner_reassignment_id`.

Old payment submissions/transactions are not copied.

## 10. No Alternative Flow

If no eligible alternative exists:

- Auction transitions `PaymentPending -> Defaulted -> Unsold`.
- `winning_bid_id` is cleared.
- Old settlement remains historical and non-current.
- Existing non-winner release flow runs with the defaulted user excluded.
- Seller deposit trigger remains `winner_default` to avoid expanding Seller Deposit lifecycle in this task.

## 11. Payment Submission Superseding

Pending winner payment submissions for the old settlement are locked and marked:

- `status = rejected`
- `review_note = winner_default_superseded`

Approved historical submissions are not changed.

## 12. Idempotency Design

Protection comes from:

- Auction row lock.
- Current settlement lock.
- Closed old settlement loses `current_marker`.
- New settlement current unique key.
- Reassignment `firstOrCreate`.
- New unique key on `auction_winner_reassignments`.
- Refund lifecycle idempotency for refund dispositions.

Replay returns current terminal result or produces a clear conflict without duplicating settlement/reassignment/deposit disposition.

## 13. Concurrency Protection

MySQL process-level concurrency test runs two workers against the same auction. Expected result is one applied default, one clear no-op/conflict, one current settlement, one reassignment, and one deposit disposition.

## 14. Actions/Resolvers Created

- `WinnerDefaultDepositDispositionResolver`

## 15. DTOs Created

- `WinnerDefaultDepositDispositionDTO`
- `WinnerDefaultDepositDisposition` enum

## 16. Repositories Modified

- `AuctionSettlementRepository::closeAsHistorical`
- `AuctionPaymentRepository::lockPendingReviewSubmissionsForSettlement`
- `AuctionWinnerReassignmentRepository::firstOrCreate`
- `AuctionWinnerReassignmentRepository::save`

## 17. Permissions

Added:

- `auction.winners.mark_defaulted`
- `auction.winners.override_payment_deadline`

`AuctionController::markWinnerDefaulted` now authorizes `markWinnerDefaulted`, not `resolveDispute`.

## 18. Audit Events

Added/expanded:

- `auction.winner_defaulted`
- `auction.winner_deposit_forfeited`
- `auction.winner_deposit_manual_review`
- `auction.alternative_winner_selected`

## 19. Outbox Events

Added/expanded:

- `auction.winner_defaulted`
- `auction.winner_deposit_forfeited`
- `auction.alternative_winner_selected`
- `auction.alternative_settlement_created`
- `auction.no_alternative_winner`

## 20. New Tests

- `tests/Feature/Auction/WinnerDefaultTest.php`
- Added MySQL process concurrency coverage in `AuctionMysqlConcurrencyTest`.
- Updated non-winner release expectation for defaulted winner deposit forfeiture.

## 21. Feature Test Results

- `php artisan test --filter=WinnerDefault`: PASS, 8 passed, 32 assertions.
- `php artisan test --filter=NonWinnerDepositRelease`: PASS, 11 passed, 1 skipped, 52 assertions.
- `php artisan test --filter=SellerDeposit`: PASS, 13 passed, 1 skipped, 45 assertions.

## 22. MySQL Concurrency Results

- `vendor\bin\phpunit --configuration=phpunit.mysql.xml --filter=WinnerDefault`: PASS, 8 tests, 32 assertions.
- `vendor\bin\phpunit --configuration=phpunit.mysql.xml --filter=/winner_default/i`: PASS, 6 tests, 35 assertions. This includes the MySQL winner default concurrency tests and overlapping seller lifecycle winner-default coverage.

Note: `php artisan test --configuration=phpunit.mysql.xml --filter=WinnerDefault` emitted a duplicate-configuration warning in this project, so PHPUnit was used directly for the MySQL configuration.

## 23. Commands Run

- `composer dump-autoload`: PASS.
- `php artisan optimize:clear`: PASS.
- `php artisan route:list`: PASS, 183 routes listed.
- `php artisan migrate:status`: PASS; new migration is pending on the current development DB.
- `php artisan test --filter=WinnerDefault`: PASS.
- `vendor\bin\phpunit --configuration=phpunit.mysql.xml --filter=WinnerDefault`: PASS.
- `vendor\bin\phpunit --configuration=phpunit.mysql.xml --filter=/winner_default/i`: PASS.
- `php artisan test --filter=NonWinnerDepositRelease`: PASS.
- `php artisan test --filter=SellerDeposit`: PASS.
- `vendor\bin\pint --test`: FAIL due pre-existing project-wide 148 style issues.
- Scoped `vendor\bin\pint --test` on modified files: PASS, 17 files.
- Syntax checks on modified PHP files: PASS.

## 24. Checks That Could Not Fully Pass

Full-project Pint remains red because of existing unrelated style issues across 407 files. The modified files pass scoped Pint.

## 25. Deferred Risks

- Full seller deposit policy expansion remains out of scope for this task.
- General cancellation and configuration snapshot refactors remain out of scope.
- Outbox consumers for new events remain future work.
- The current DB has the new migration pending until `php artisan migrate` is run outside test databases.
