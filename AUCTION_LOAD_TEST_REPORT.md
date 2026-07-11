# Auction Load Test Report

## Script

`load-tests/auction-hot-bid.js` defines the intended k6 scenario.

## Current Environment

avel environment.- Workspace local PHP/Lar
- No production-like MySQL/PostgreSQL service, Redis, queue workers, or object storage benchmark target was available.

## Result

Load tests were documented but not executed against a production-like environment in this workspace. Current measured results are therefore unavailable for p95, p99, lock wait, throughput, deadlocks, queue lag, and DB CPU. The Laravel unit/feature suite passed locally, but that is not a load test.

## Required Staging Run

Run with a real primary database:

```bash
k6 run load-tests/auction-hot-bid.js
```

Record p50, p95, p99, errors, DB connections, lock waits, deadlocks, queue lag, and memory.
