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

## AI Content Review

The content review subsystem runs on its own queue and its own worker process, so a stalled provider
cannot starve the auction jobs above:

- `php artisan queue:work --queue=content-review --tries=3 --timeout=120`
- Scheduler: `content-review:dispatch-pending` every minute, `content-review:sweep-alerts` every
  five minutes.
- `CACHE_STORE` must be `database` or `redis`; the circuit breaker, budget guard, concurrency
  limiter, alert state and worker heartbeat are shared atomic cache operations.

Full operational detail is in `AI_CONTENT_REVIEW_RUNBOOK.md`.

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
