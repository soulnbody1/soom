<?php

use App\Http\Controllers\Ad\AdController;
use App\Http\Controllers\Admin\AdminUserAuctionsController;
use App\Http\Controllers\Admin\AdminUserConversationController;
use App\Http\Controllers\Admin\AdminUserFinanceController;
use App\Http\Controllers\Admin\AdminUserProfileController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Attribute\AttributeController;
use App\Http\Controllers\Attribute\AttributeOptionController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\CharitySystemController;
use App\Http\Controllers\Location\CityController;
use App\Http\Controllers\Location\CountryController;
use App\Http\Controllers\Location\StateController;
use App\Http\Controllers\User\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('countries', CountryController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('states', StateController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('citys', CityController::class)->only(['store', 'update', 'destroy']);

    Route::prefix('attributes')->group(function () {
        Route::post('/', [AttributeController::class, 'store']);
        Route::put('/{id}', [AttributeController::class, 'update']);
        Route::delete('/{id}', [AttributeController::class, 'destroy']);
        Route::get('/{id}', [AttributeController::class, 'getOptionsByAttributeId']);
        Route::post('/sync-attributes', [AttributeController::class, 'syncAttributesToCategory']);
        Route::post('/exclude', [AttributeController::class, 'excludeAttributeFromCategory']);
        Route::post('/include', [AttributeController::class, 'includeAttributeBack']);
    });

    Route::prefix('users')->group(function () {
        Route::get('/', [ProfileController::class, 'users']);
        Route::get('/analytics', [ProfileController::class, 'analytics']);
        Route::get('/search', [ProfileController::class, 'search']);
        Route::delete('/force-delete/{id}', [ProfileController::class, 'destroybyadmin']);
        Route::put('/toggle-block/{id}', [ProfileController::class, 'toggleBlock']);

        Route::prefix('{user}')->group(function () {
            $routes = [
                '/' => [AdminUserProfileController::class, 'show'],
                '/ads' => [AdminUserProfileController::class, 'ads'],
                '/favorites' => [AdminUserProfileController::class, 'favorites'],
                '/saved' => [AdminUserProfileController::class, 'saved'],
                '/activity' => [AdminUserProfileController::class, 'activity'],
                '/auctions' => [AdminUserAuctionsController::class, 'index'],
                '/financial/summary' => [AdminUserFinanceController::class, 'summary'],
                '/financial/refunds' => [AdminUserFinanceController::class, 'refunds'],
                '/financial/payouts' => [AdminUserFinanceController::class, 'payouts'],
                '/financial/deposits' => [AdminUserFinanceController::class, 'deposits'],
                '/financial/payment-submissions' => [AdminUserFinanceController::class, 'submissions'],
                '/financial/payment-transactions' => [AdminUserFinanceController::class, 'transactions'],
                '/payout-destinations' => [AdminUserFinanceController::class, 'destinations'],
                '/conversations' => [AdminUserConversationController::class, 'index'],
                '/conversations/{partner}/messages' => [AdminUserConversationController::class, 'messages'],
            ];

            foreach ($routes as $uri => $action) {
                Route::get($uri, $action)->withTrashed()->whereNumber(['user', 'partner']);
            }
        });
    });

    Route::prefix('banners')->group(function () {
        Route::get('/', [BannerController::class, 'indexforadmin']);
        Route::get('/{id}', [BannerController::class, 'show']);
        Route::post('/', [BannerController::class, 'store']);
        Route::put('/{id}', [BannerController::class, 'update']);
        Route::delete('/{id}', [BannerController::class, 'destroy']);
    });

    Route::prefix('charity_system')->group(function () {
        Route::get('/', [CharitySystemController::class, 'indexforadmin']);
        Route::get('/{id}', [CharitySystemController::class, 'show']);
        Route::post('/', [CharitySystemController::class, 'store']);
        Route::put('/{id}', [CharitySystemController::class, 'update']);
        Route::delete('/{id}', [CharitySystemController::class, 'destroy']);
    });

    Route::prefix('announcements')->group(function () {
        Route::get('/', [AnnouncementController::class, 'indexforadmin']);
        Route::get('/{id}', [AnnouncementController::class, 'show']);
        Route::post('/', [AnnouncementController::class, 'store']);
        Route::post('/{id}', [AnnouncementController::class, 'update']);
        Route::delete('/{id}', [AnnouncementController::class, 'destroy']);
    });

    Route::prefix('ads')->group(function () {
        Route::get('/', [AdController::class, 'ads']);
        Route::get('/search', [AdController::class, 'search_for_admin']);
        Route::delete('/force-delete/{id}', [AdController::class, 'destroybyadmin']);
        Route::put('/toggle-block/{id}', [AdController::class, 'toggleBlock']);
        Route::put('/toggle-featured/{id}', [AdController::class, 'toggleFeatured']);
    });

    Route::prefix('attribute-options')->group(function () {
        Route::post('/', [AttributeOptionController::class, 'store']);
        Route::put('/{id}', [AttributeOptionController::class, 'update']);
        Route::delete('/{id}', [AttributeOptionController::class, 'destroy']);
    });
});
