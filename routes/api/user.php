<?php

use App\Http\Controllers\Ad\AdController;
use App\Http\Controllers\Ad\AdReelViewController;
use App\Http\Controllers\Ad\FavoriteController;
use App\Http\Controllers\Ad\MyAdController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Message\ConversationController;
use App\Http\Controllers\Message\MessageController;
use App\Http\Controllers\Notification\DeviceTokenController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\User\AccountDashboardSummaryController;
use App\Http\Controllers\User\AccountShellController;
use App\Http\Controllers\User\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:admin,user'])->prefix('soom')->group(function () {

    Route::get('/account/shell', AccountShellController::class);
    Route::get('/account/dashboard-summary', AccountDashboardSummaryController::class);

    // ============= الاعلانات =============
    Route::prefix('ads')->group(function () {
        Route::post('/', [AdController::class, 'store'])->middleware('throttle:ads-write');
        Route::put('/my/{ad}', [AdController::class, 'update'])->whereUlid('ad')->middleware('throttle:ads-write');
        Route::post('/ad-reel-views', [AdReelViewController::class, 'store'])->middleware('throttle:ads-engagement');
        Route::get('/my', [MyAdController::class, 'index']);
        Route::delete('/my/soft-delete/{ad}', [MyAdController::class, 'destroy'])->whereUlid('ad');
        Route::delete('/my/force-delete/{ad}', [MyAdController::class, 'forceDelete'])->whereUlid('ad');
        Route::post('/my/restore/{ad}', [MyAdController::class, 'restore'])->whereUlid('ad');
        Route::delete('/my/reel/{id}', [AdReelViewController::class, 'delete']);
    });

    // ============= المفضلة =============
    Route::prefix('favorites')->group(function () {
        Route::get('/', [FavoriteController::class, 'index']);
        Route::post('/', [FavoriteController::class, 'store'])->middleware('throttle:ads-engagement');
        Route::delete('/{ad}', [FavoriteController::class, 'destroy'])->whereUlid('ad')->middleware('throttle:ads-engagement');
    });

    // ============= الرسائل =============
    Route::prefix('messages')->group(function () {
        Route::post('/', [MessageController::class, 'store'])->middleware('throttle:chat-send');
        Route::get('/chat/{userId}', [ConversationController::class, 'show'])
            ->whereNumber('userId')->middleware('throttle:chat-read');
        Route::get('/conversations', [ConversationController::class, 'index'])->middleware('throttle:chat-read');
        Route::post('/markAsRead', [ConversationController::class, 'markAsRead'])->middleware('throttle:chat-write');
        Route::delete('/delete', [MessageController::class, 'delete'])->middleware('throttle:chat-write');
        Route::get('/search', [ConversationController::class, 'search'])->middleware('throttle:chat-read');
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])
            ->middleware('throttle:notifications-read');
        Route::post('/mark-all-as-read', [NotificationController::class, 'markAsRead'])
            ->middleware('throttle:notifications-write');
        Route::put('/{id}/read', [NotificationController::class, 'markSingleAsRead'])
            ->whereUuid('id')->middleware('throttle:notifications-write');

        Route::post('/device-token', [DeviceTokenController::class, 'store'])
            ->middleware('throttle:notifications-write');
        Route::delete('/device-token', [DeviceTokenController::class, 'destroy'])
            ->middleware('throttle:notifications-write');
    });

    // ============= المستخدم =============
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::post('/', [ProfileController::class, 'update'])->middleware('throttle:profile-write');
        Route::delete('/', [ProfileController::class, 'destroy']);
    });

    Route::post('/change-password', PasswordController::class);

    // ============= تسجيل الخروج ============
    Route::post('/logout', [SessionController::class, 'logout']);
});
