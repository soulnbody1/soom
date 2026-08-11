<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Soom Auction Operations

The auction subsystem is isolated under `App\Domain\Auction`, `App\Services\Auction`, `App\Models\Auction`, `App\Http\Controllers\Auction`, and `routes/api/auction.php`.

Operational checklist:

- Run normal migrations with `php artisan migrate`. Do not use `migrate:fresh` for auction rollout; the auction migrations only drop known legacy auction tables when an incompatible legacy auction schema is detected.
- Run the scheduler with Laravel's normal scheduler process. `routes/console.php` registers `auction:run-operations` every minute and `auction:reconcile` every fifteen minutes.
- Run a queue worker for auction jobs: `php artisan queue:work --tries=3`.
- Configure private payment receipt storage with the `spaces_private` disk. Payment receipt uploads use private object storage and authorized temporary URLs.
- Review manual payment submissions through `api/admin/auctions/payment-submissions/{paymentSubmission}/review`; payment methods are addressed by public ULID, not database IDs.
- Process outbox messages through the scheduled outbox job. Outbox rows are leased before notifications are sent.
- Exercise the hot bid path with k6: `k6 run load-tests/auction-hot-bid.js -e BASE_URL=http://127.0.0.1:8000 -e AUCTION_ID=<public-id> -e AUTH_TOKEN=<token>`.

## AI Content Review

Isolated under `App\Domain\ContentReview`, `App\Services\ContentReview`, `App\Models\ContentReview`, `App\Http\Controllers\ContentReview`, and `routes/api/content_review.php`. It ships disabled: `CONTENT_REVIEW_ENABLED=false` and a seeded `mode=manual` settings version, which makes runtime behaviour identical to the platform without it.

- Run a second, dedicated queue worker: `php artisan queue:work --queue=content-review --tries=3 --timeout=120`. Keep it separate from the `default` worker so a stalled provider cannot starve auction jobs.
- Set `CACHE_STORE` to `database` or `redis`. The circuit breaker, budget guard, concurrency limiter, alert state and worker heartbeat are shared atomic cache operations and do not work on the `array` store.
- `routes/console.php` registers `content-review:dispatch-pending` every minute and `content-review:sweep-alerts` every five minutes. `content-review:backfill` and `content-review:reconcile` are run by hand and default to a dry run.
- Full operational detail — env reference, health checks, alerts, recovery, rollout stages, kill switch and rollback — is in [`AI_CONTENT_REVIEW_RUNBOOK.md`](AI_CONTENT_REVIEW_RUNBOOK.md).

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
