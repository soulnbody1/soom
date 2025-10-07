<?php

use App\Http\Controllers\Ad\AdController;
use App\Http\Controllers\Ad\AdReelViewController;
use App\Http\Controllers\Ad\FavoriteController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Message\MessageController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\User\ProfileController;
use Illuminate\Support\Facades\Route;


Route::middleware(['auth:sanctum', 'role:admin,user'])->prefix('soom')->group(function () {

    // ============= الاعلانات =============
    Route::prefix('ads')->group(function () {
        Route::post('/', [AdController::class, 'store']);
        Route::post('/ad-reel-views', [AdReelViewController::class, 'store']);
        Route::get('/my', [AdController::class, 'myads']);
        Route::put('/my/{ad}', [AdController::class, 'update']);
        Route::delete('/my/soft-delete/{ad}', [AdController::class, 'destroy']);
        Route::delete('/my/force-delete/{ad}', [AdController::class, 'forceDelete']);
        Route::post('/my/restore/{id}', [AdController::class, 'restore']);
        Route::delete('/my/reel/{id}', [AdReelViewController::class, 'delete']);
    });




    // ============= المفضلة =============
    Route::prefix('favorites')->group(function () {
        Route::get('/', [FavoriteController::class, 'index']);
        Route::post('/', [FavoriteController::class, 'store']);
        Route::delete('/{ad}', [FavoriteController::class, 'destroy']);
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
