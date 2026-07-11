# Auction Current System Audit

## Current Flow Found

- The old auction was a standalone record owned by `user_id`, with decimal `starting_price`, mutable `current_bid`, `min_accept_price`, and statuses such as `pending_payment`, `active`, `extended`, `closed`, and `completed`.
- Seller and bidder deposits were represented partly on auction/bid records and partly in `auction_deposits`.
- Bidder deposit submission created a zero-amount placeholder bid, then later bid placement mutated that same bid row.
- Payment slips were broad polymorphic records against either an auction or bid.
- Finalization selected the mutable winning bid, set `winner_id`, applied/refunded deposits, and marked the auction completed or closed.

## Problems

- Financial amounts used decimal casts and several service/controller float casts.
- Bids were not append-only; bid amount and winning state were updated in place.
- `is_winning` stored a user id in a boolean-like column.
- `extended` was modeled as an auction status instead of a live-auction time extension.
- Seller deposit approval directly activated auctions and skipped review/scheduling state clarity.
- Payment receipt visibility was not private enough for bidder receipts.
- Admin permissions were role-wide instead of action-specific in the auction flow.
- Finalization, refunds, and payment review were not fully idempotent.
- Resources/controllers performed query work and exposed numeric IDs.
- Patch migrations accumulated legacy columns and compatibility fixes.

## Removed Legacy Surface

- Old root models, services, repositories, requests, resources, jobs, events, listeners, notifications, and patch migrations for auctions were deleted.
- Old tables removed from the intended schema: `auction_images`, `auction_rules`, `auctions_configurations`, `payment_slips`.

## Replacement Direction

- Single-lot auction model.
- Public ULIDs and internal bigint keys.
- Integer minor-unit money.
- Append-only bids.
- Explicit participants, terms acceptance, deposits, payment submissions, payment transactions, refunds, settlements, status history, activity log, metrics, views, and outbox.
