# Auction Deployment And Operations

## Scheduler

Registered in `routes/console.php`:

- `auction:run-operations` every minute with `withoutOverlapping`.
- `auction:reconcile` every fifteen minutes with `withoutOverlapping`.

## Queues

Jobs:

- `StartDueAuctionsJob`
- `FinalizeExpiredAuctionsJob`
- `RefundPendingAuctionDepositsJob`
- `DispatchAuctionOutboxJob`

Use Redis queue workers in production and configure failed job monitoring.

## Storage

- Auction media and payment receipts use the `spaces` disk.
- Receipt access should be through signed, authorized endpoints if downloads are added.

## Environment

- Configure primary DB for writes.
- Use Redis for queues/cache/scheduler locks.
- Configure S3-compatible object storage for `spaces`.
- Monitor slow queries, lock waits, failed jobs, outbox backlog, pending payments, and refund failures.

## Backup And Recovery

- Back up auction tables before destructive migration.
- Use point-in-time recovery for the primary database.
- Preserve audit, payment, refund, and settlement records for legal/financial review.
