# Auction Assumptions And Policies

- The platform currently needs a single-lot auction, not multi-lot events.
- Reserve price is optional. If present, it must be greater than or equal to the starting amount.
- Reserve amount is not exposed directly to public users; the API exposes whether reserve is met.
- First accepted bid must be at least `starting_amount`.
- Later accepted bids must be at least current leading bid plus `minimum_bid_increment`.
- Server time is the only authority.
- Bids at exactly `ends_at` are rejected.
- Extension keeps the auction in `live`; it only changes `ends_at`.
- Payment provider integration is represented by idempotent transaction fields but current implementation supports manual review first.
- The platform does not create an internal ledger because the current flow does not hold internal balances outside provider/manual payment records.
- Seller and bidder receipt files are private operational files and are not exposed as public media.
