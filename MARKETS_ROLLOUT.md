# Markets — Jordan and Egypt

Soom runs one account across several markets. Jordan and Egypt are both live; the UAE market row exists but is inactive and has no hosts.

A market is not an authorization boundary. It selects which catalogue a visitor browses. A signed-in account keeps its full history across every market it has traded in.

## Contract

- `GET /api/markets` lists active markets. `GET /api/market-config` returns the market the request host selected. `GET /api/admin/markets` lists every market, active or not, for an authenticated admin.
- The public API host selects the market: `api-jo.<root>` and `api-eg.<root>` are exact values in `markets.api_host`. An unknown or inactive host returns 404. `X-Soom-Market` is always stripped; a client cannot choose a market.
- `api-admin.<root>` is a separate host. Its default view is every market. `?market=<code>` narrows it, and `admin_market_required` makes that explicit for writes: without it the API returns **422 `market_required`**.
- `SOOM_LEGACY_API_HOST` keeps the pre-split API host serving `SOOM_LEGACY_MARKET_CODE` (Jordan by default) for mobile clients that have not been updated. Its responses carry `Deprecation: true` and, when `SOOM_LEGACY_SUNSET` is set, a `Sunset` date. It is unset by default; it cannot reach admin routes, and it fails closed when its market is inactive.
- A request-scoped market context is resolved once and activates one Eloquent scope on 38 models. Public ad, home, search, category, detail, reel, seller-count and auction routes inherit it. Account history, admin routes, webhooks and jobs run in an explicit global or system context instead.
- Query-builder paths that cannot inherit an Eloquent scope go through `MarketQuery::table()`, which filters only in the modes that own a market and refuses a table that was never registered.
- Ad prices are stored as `decimal(13,3)` with an explicit currency, and the accepted number of decimals follows the market currency (JOD 3, EGP 2).
- Auction terms and configuration versions are per market. Version numbers are unique **per market**, so each market numbers its own from 1. Snapshot creation rejects a source version from another market.
- Payment methods belong to a market. `PaymentMethodMarketRule` requires the method, the auction and the market to agree on both market and currency.
- `payment_provider_events` is the one market-scoped table whose `market_id` stays nullable. It is an inbound audit log, and a webhook's market is only knowable after it matches a transaction; an unmatched or unsigned event is still recorded, with no market, so the audit trail survives.

## Adding or launching a market

```bash
php artisan market:provision EG
```

The command loads the governorates and cities, mirrors the source market's visible categories, creates the auction terms and configuration versions, creates the payment methods, copies the support contact, then verifies that all of it is present before it sets `is_active = true`. If anything is missing it names it and refuses to activate. Running it again changes nothing.

A market's own values live in one file per market — `app/Services/Market/Profiles/EgyptMarketProfile.php` — so the money and the legal text are in a single reviewable place rather than spread through the code.

### Egypt's launch values

| Setting | Value |
|---|---|
| Currency | EGP, 2 decimals |
| Seller deposit | 700.00 EGP |
| Bidder deposit | 350.00 EGP |
| Minimum bid increment | 70.00 EGP |
| Platform fee | 2.5% |
| Payment | `eg_manual_transfer` — manual bank transfer, admin reviewed. No online provider. |
| Deadlines and deposit policies | Identical to Jordan |

**The Egyptian auction terms are a draft.** `EgyptMarketProfile::auctionTerms()` says so in its own title. It covers deposits, payment, handover, breach and disputes, and it needs Egyptian legal review. Publishing a corrected version from the dashboard needs no deploy.

Banners, announcements and charity entries start empty for a new market; their endpoints return an empty list, not an error. The support contact is copied from the source market when it has one, and otherwise falls back to `config('support.contact')`.

## Migrating a database that already holds data

1. Back up. `2026_09_17_130000_contract_market_ownership` has an intentionally empty `down()`, so there is no rollback — only the backup.
2. Configure `SOOM_ROOT_DOMAIN`, `SOOM_ADMIN_API_HOST`, `SOOM_TRUSTED_PROXY_IPS` and, if old clients still exist, `SOOM_LEGACY_API_HOST`. The migration writes the derived hosts into the market rows; changing DNS or the environment afterwards does not rewrite them. Provision DNS and TLS, and restrict ingress so the routed Host cannot be spoofed.
3. Dry-run on a restored copy of the real database, never on an empty one. Data that only exists in production is what breaks this migration: orphaned rows, ads with an unexpected `country_id`, and versions or payment methods shared between markets, which the backfill refuses to split on its own.
4. `php artisan migrate --pretend` prints the DDL and skips the data steps, so it is safe but incomplete; only the dry-run on a copy exercises the backfill.
5. `php artisan migrate`, then `php artisan market:validate`, then `php artisan market:provision <code>` for each market being launched.

The backfill assigns every pre-market row to Jordan, splits versions and payment methods by the markets of the rows referencing them, and derives each content review and outbox message from its subject. A row whose owner no longer exists is assigned to the legacy market and counted as orphaned in the log; a row whose owner exists but has no market stops the migration, because that is a real inconsistency rather than history.

## Testing

```bash
php artisan test --filter=Market
php vendor/bin/phpunit -c phpunit.market-mysql.xml
```

The MySQL suite is the one that proves the composite foreign keys and the pre-contract backfill window; those tests skip on SQLite. The older parts of the full suite are not green and were not part of this work: some fixtures invent countries with JOD, and some send numeric ad ids to endpoints that now take ULIDs only.
