<?php

use App\Http\Controllers\Auction\AuctionController;
use App\Http\Controllers\Auction\AuctionTermsController;
use App\Http\Controllers\Auction\BidController;
use App\Http\Controllers\Auction\PaymentMethodController;
use App\Http\Controllers\Auction\PaymentSubmissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auctions')->group(function () {
    Route::get('/', [AuctionController::class, 'index']);
    Route::get('/{auction}', [AuctionController::class, 'show']);
    Route::get('/{auction}/bids', [BidController::class, 'index']);
});

Route::prefix('soom')->group(function () {
    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::get('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'show']);
    Route::get('/auction-terms', [AuctionTermsController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'role:admin,user'])->prefix('soom')->group(function () {
    Route::get('/my/auctions', [AuctionController::class, 'mine']);
    Route::get('/my/bids', [BidController::class, 'mine']);

    Route::prefix('auctions')->group(function () {
        Route::post('/', [AuctionController::class, 'store']);
        Route::post('/{auction}/submit-review', [AuctionController::class, 'submitForReview']);
        Route::post('/{auction}/seller-deposit', [AuctionController::class, 'submitSellerDeposit']);
        Route::post('/{auction}/register', [AuctionController::class, 'register']);
        Route::post('/{auction}/accept-terms', [AuctionController::class, 'acceptTerms']);
        Route::post('/{auction}/bidder-deposit', [AuctionController::class, 'submitBidderDeposit']);
        Route::post('/{auction}/bids', [BidController::class, 'store']);
        Route::post('/{auction}/winner-payment', [AuctionController::class, 'submitWinnerPayment']);
        Route::post('/{auction}/complete-handover', [AuctionController::class, 'completeHandover']);
        Route::delete('/{auction}', [AuctionController::class, 'cancel']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/auctions')->group(function () {
    Route::get('/', [AuctionController::class, 'all']);
    Route::post('/terms', [AuctionTermsController::class, 'store']);
    Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::put('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update']);
    Route::post('/{auction}/review', [AuctionController::class, 'review']);
    Route::get('/payment-submissions', [PaymentSubmissionController::class, 'index']);
    Route::post('/payment-submissions/{paymentSubmission}/review', [PaymentSubmissionController::class, 'review']);
});
