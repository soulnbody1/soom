<?php

use App\Http\Controllers\MarketController;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('/status', StatusController::class);
Route::middleware(['api_maintenance'])->group(function () {
    Route::get('/markets', [MarketController::class, 'index']);
    Route::middleware('market')->group(function () {
        Route::get('/market-config', [MarketController::class, 'current']);
        require __DIR__.'/api/auth.php';
        require __DIR__.'/api/admin.php';
        require __DIR__.'/api/user.php';
        require __DIR__.'/api/guest.php';
        require __DIR__.'/api/auction.php';
        require __DIR__.'/api/content_review.php';
        require __DIR__.'/api/seller_rating.php';
        require __DIR__.'/api/support.php';
    });
});
