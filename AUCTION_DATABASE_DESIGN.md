# Auction Database Design

## Tables

- `auctions`: single-lot auction snapshot, state, timing, current/winning bid pointers.
- `auction_media`: private object references for auction media.
- `auction_participants`: bidder registration and qualification.
- `auction_bids`: append-only accepted bids.
- `auction_deposits`: seller/bidder deposit lifecycle.
- `payment_submissions`: private manual payment receipt submissions.
- `payment_transactions`: approved provider/manual payment facts.
- `refund_transactions`: idempotent refund facts.
- `auction_settlements`: winner payment and handover lifecycle.
- `auction_status_history`: state transitions.
- `auction_activity_logs`: audit events.
- `auction_terms_versions`: immutable published terms snapshots.
- `auction_terms_acceptances`: bidder acceptance records.
- `auction_metrics`: denormalized counters.
- `auction_views`: deduplicated viewer hashes.
- `outbox_messages`: reliable event publication.
- `payment_methods`: configured payment methods.

## Constraints And Indexes

- `public_id` unique on public resources.
- `auction_bids`: unique `(auction_id, sequence_number)` and `(auction_id, bidder_id, idempotency_key)`.
- `auction_deposits`: unique `(auction_id, user_id, type)`.
- `auction_settlements`: unique one settlement per auction and one per winning bid.
- Hot queries indexed by `(status, starts_at)`, `(status, ends_at)`, category/status/end time, seller/status, bid rank, participant user/status, payment purpose/status, and outbox status/availability.
- MySQL/PostgreSQL check constraints enforce reserve/start amount, start/end time, deposit conservation, and settlement amount equation.

## Money

All financial amounts are stored as unsigned integer minor units with `currency_code CHAR(3)`. No float/double casts are used in auction financial code.
