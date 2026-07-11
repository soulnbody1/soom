<?php

use App\Domain\Auction\Exceptions\AuctionException;
use App\Http\Middleware\ApiMaintenanceMode;
use App\Http\Middleware\RoleMiddleware;
use App\Providers\BroadcastServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        app('router')->aliasMiddleware('role', RoleMiddleware::class);
        app('router')->aliasMiddleware('api_maintenance', ApiMaintenanceMode::class);

        //
    })->withProviders([
        BroadcastServiceProvider::class,
    ])
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuctionException $exception, $request) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        });

        $exceptions->render(function (AuthorizationException $exception, $request) {
            return response()->json([
                'success' => false,
                'message' => __('auction.errors.forbidden'),
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $exception, $request) {
            return response()->json([
                'success' => false,
                'message' => __('auction.errors.auction_not_found'),
            ], 404);
        });
    })->create();
