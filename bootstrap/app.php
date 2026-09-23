<?php

use App\Domain\Auction\Exceptions\AuctionErrorCodeCatalog;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\ContentReview\Exceptions\ContentReviewErrorCodeCatalog;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Exceptions\User\AccountDeletionBlockedException;
use App\Http\Middleware\ApiMaintenanceMode;
use App\Http\Middleware\RequireAdminMarket;
use App\Http\Middleware\ResolveMarketContext;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\UseAccountGlobalContext;
use App\Http\Responses\ApiErrorResponse;
use App\Models\Auction\Auction;
use App\Models\ContentReview\ContentReview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustHosts(at: fn (): array => array_values(array_filter([
            '^api-[a-z]{2}\\.'.preg_quote(config('markets.root_domain'), '/').'$',
            '^'.preg_quote(config('markets.admin_api_host'), '/').'$',
            config('markets.legacy_api_host') ? '^'.preg_quote((string) config('markets.legacy_api_host'), '/').'$' : null,
        ])), subdomains: false);

        app('router')->aliasMiddleware('role', RoleMiddleware::class);
        app('router')->aliasMiddleware('api_maintenance', ApiMaintenanceMode::class);
        app('router')->aliasMiddleware('market', ResolveMarketContext::class);
        app('router')->aliasMiddleware('account_global', UseAccountGlobalContext::class);
        app('router')->aliasMiddleware('admin_market_required', RequireAdminMarket::class);
        $middleware->priority([
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            ResolveMarketContext::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            UseAccountGlobalContext::class,
            RequireAdminMarket::class,
            SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        //
    })
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withExceptions(function (Exceptions $exceptions) {
        $wantsJson = fn ($request): bool => $request->expectsJson() || $request->is('api/*');

        $exceptions->render(function (AuctionException $exception, $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $code = $exception->getErrorCode()
                ?? app(AuctionErrorCodeCatalog::class)->codeFor($exception->getMessage())
                ?? 'auction_error';

            return ApiErrorResponse::make($exception->getMessage(), $code, $exception->getStatusCode());
        });

        $exceptions->render(function (ContentReviewException $exception, $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $code = $exception->getErrorCode()
                ?? app(ContentReviewErrorCodeCatalog::class)->codeFor($exception->getMessage())
                ?? 'content_review_error';

            return ApiErrorResponse::make($exception->getMessage(), $code, $exception->getStatusCode());
        });

        $exceptions->render(function (AccountDeletionBlockedException $exception, $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return ApiErrorResponse::make($exception->getMessage(), 'account_deletion_blocked', 409);
        });

        $exceptions->render(function (ValidationException $exception, $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return ApiErrorResponse::make(
                $exception->getMessage(),
                'validation_failed',
                422,
                ['errors' => $exception->errors()]
            );
        });

        $exceptions->render(function (AuthenticationException $exception, $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return ApiErrorResponse::make(__('auction.errors.unauthenticated'), 'unauthenticated', 401);
        });

        $forbidden = function (string $denial) {
            $isGeneric = $denial === '' || $denial === 'This action is unauthorized.';

            return ApiErrorResponse::make(
                $isGeneric ? __('auction.errors.forbidden') : $denial,
                'forbidden',
                403
            );
        };

        $exceptions->render(function (AuthorizationException $exception, $request) use ($wantsJson, $forbidden) {
            if (! $wantsJson($request)) {
                return null;
            }

            return $forbidden($exception->getMessage());
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, $request) use ($wantsJson, $forbidden) {
            if (! $wantsJson($request)) {
                return null;
            }

            return $forbidden($exception->getMessage());
        });

        $exceptions->render(function (ModelNotFoundException $exception, $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return match ($exception->getModel()) {
                Auction::class => ApiErrorResponse::make(__('auction.errors.auction_not_found'), 'auction_not_found', 404),
                ContentReview::class => ApiErrorResponse::make(__('content_review.errors.review_not_found'), 'review_not_found', 404),
                default => ApiErrorResponse::make(__('auction.errors.not_found'), 'not_found', 404),
            };
        });

        $exceptions->render(function (NotFoundHttpException $exception, $request) use ($wantsJson) {
            $previous = $exception->getPrevious();

            if (! $wantsJson($request) || ! $previous instanceof ModelNotFoundException) {
                return null;
            }

            return match ($previous->getModel()) {
                Auction::class => ApiErrorResponse::make(__('auction.errors.auction_not_found'), 'auction_not_found', 404),
                ContentReview::class => ApiErrorResponse::make(__('content_review.errors.review_not_found'), 'review_not_found', 404),
                default => ApiErrorResponse::make(__('auction.errors.not_found'), 'not_found', 404),
            };
        });

        $exceptions->render(function (ThrottleRequestsException $exception, $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;

            return ApiErrorResponse::make(
                __('auction.errors.too_many_requests'),
                'too_many_requests',
                429,
                $retryAfter === null ? [] : ['retry_after' => (int) $retryAfter]
            );
        });
    })->create();
