<?php

use App\Http\Controllers\Ad\AdController;
use App\Http\Controllers\Ad\AdReelViewController;
use App\Http\Controllers\Ad\FavoriteController;
use App\Http\Controllers\Ad\MyAdController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Message\MessageController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\User\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:admin,user'])->prefix('soom')->group(function () {

    // ============= الاعلانات =============
    Route::prefix('ads')->group(function () {
        Route::post('/', [AdController::class, 'store'])->middleware('throttle:ads-write');
        Route::put('/my/{ad}', [AdController::class, 'update'])->middleware('throttle:ads-write');
        Route::post('/ad-reel-views', [AdReelViewController::class, 'store'])->middleware('throttle:ads-engagement');
        Route::get('/my', [MyAdController::class, 'index']);
        Route::delete('/my/soft-delete/{ad}', [MyAdController::class, 'destroy']);
        Route::delete('/my/force-delete/{ad}', [MyAdController::class, 'forceDelete']);
        Route::post('/my/restore/{id}', [MyAdController::class, 'restore']);
        Route::delete('/my/reel/{id}', [AdReelViewController::class, 'delete']);
    });

    // ============= المفضلة =============
    Route::prefix('favorites')->group(function () {
        Route::get('/', [FavoriteController::class, 'index']);
        Route::post('/', [FavoriteController::class, 'store'])->middleware('throttle:ads-engagement');
        Route::delete('/{ad}', [FavoriteController::class, 'destroy'])->middleware('throttle:ads-engagement');
    });

    // ============= الرسائل =============
    Route::prefix('messages')->group(function () {
        Route::post('/', [MessageController::class, 'store']);
        Route::get('/chat/{userId}', [MessageController::class, 'getConversation']);
        Route::get('/conversations', [MessageController::class, 'getConversationsList']);
        Route::post('/markAsRead', [MessageController::class, 'markAsRead']);
        Route::delete('/delete', [MessageController::class, 'delete']);
        Route::get('/search', [MessageController::class, 'searchConversations']);
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/mark-all-as-read', [NotificationController::class, 'markAsRead']);
        Route::put('/{id}/read', [NotificationController::class, 'markSingleAsRead']);
    });

    // ============= المستخدم =============
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::post('/', [ProfileController::class, 'update']);
        Route::delete('/', [ProfileController::class, 'destroy']);
    });

    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // ============= تسجيل الخروج ============
    Route::post('/logout', [AuthController::class, 'logout']);
});
