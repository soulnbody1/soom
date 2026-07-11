# Auction Migration Report

## Strategy

The auction feature was not launched, so legacy auction migrations were removed and replaced with a clean initial auction schema. No `migrate:fresh` was run.

## Deleted Migration Concepts

- Patch migrations for auction metrics, payment slips, deposit normalization, bid/deposit guards, auction settings hardening, and old deposit columns.

## New Schema

Created by `database/migrations/2025_06_12_090000_create_auctions_table.php` for new installs and by `database/migrations/2026_07_11_180000_rebuild_auction_schema.php` for existing dev/staging databases that had the old auction schema marked as already migrated:

- `payment_methods`
- `auction_terms_versions`
- `auctions`
- `auction_media`
- `auction_participants`
- `auction_terms_acceptances`
- `auction_deposits`
- `payment_submissions`
- `auction_bids`
- `auction_settlements`
- `payment_transactions`
- `refund_transactions`
- `auction_status_history`
- `auction_activity_logs`
- `auction_metrics`
- `auction_views`
- `outbox_messages`

## Rollback

Rollback drops the auction-owned tables only. It does not affect unrelated user, ad, category, location, message, notification, or banner tables.

## Executed

- `php artisan migrate` ran the scoped rebuild migration successfully.
- `php artisan db:seed --class=AuctionSeeder` seeded the default manual payment method and terms version.
- `php artisan migrate:status` shows `2026_07_11_180000_rebuild_auction_schema` as run.
