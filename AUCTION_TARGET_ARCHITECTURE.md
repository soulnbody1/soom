# Auction Target Architecture

The auction system is a modular monolith.

## Layers

- `App\Domain\Auction`: enums, value objects, and domain exceptions.
- `App\Application\Auction`: actions and application services for transactions, state transitions, metrics, audit, and outbox.
- `App\Models\Auction`: persistence models for the auction bounded context.
- `App\Http\Controllers\Auction`, `Requests\Auction`, `Resources\Auction`: API boundary only.
- `App\Jobs\Auction`, `App\Console\Commands\Auction`: operational processing.

## Key Rules

- Controllers never directly mutate status or money.
- State transitions pass through `AuctionStateMachine`.
- Financial actions write audit and outbox records.
- Bids are append-only rows with deterministic sequence numbers.
- Public API uses `public_id`, not internal numeric ids for auction resources.
- Payment receipts are stored privately and represented through `payment_submissions`, not a general polymorphic slip table.

## Scalability Position

The design supports multiple application servers by keeping write truth in the primary database, using row locks for hot auction writes, idempotency keys for retries, and queue-safe jobs. No claim is made that this deployment supports millions of active concurrent bidders without production-like benchmark evidence.
