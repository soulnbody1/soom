<?php

use App\Http\Controllers\SellerRating\SellerProfileController;
use App\Http\Controllers\SellerRating\SellerRatingController;
use Illuminate\Support\Facades\Route;

Route::prefix('soom/sellers')->group(function () {
    Route::middleware('throttle:seller-rating-read')->group(function () {
        Route::get('/{seller}', SellerProfileController::class)->whereNumber('seller');
        Route::get('/{seller}/ratings', [SellerRatingController::class, 'index'])->whereNumber('seller');
    });

    Route::middleware(['auth:sanctum', 'role:user', 'throttle:seller-rating-write'])->group(function () {
        Route::put('/{seller}/rating', [SellerRatingController::class, 'store'])->whereNumber('seller');
        Route::delete('/{seller}/rating', [SellerRatingController::class, 'destroy'])->whereNumber('seller');
    });
});
