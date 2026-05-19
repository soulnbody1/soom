<?php

use App\Http\Controllers\Auction\AuctionController;
use App\Http\Controllers\Auction\BidController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auction Routes
|--------------------------------------------------------------------------
|
| Routes for auction system - both public and protected
|
*/

// Public routes (Guest + Auth)
Route::prefix('auctions')->group(function () {
    // List all active auctions
    Route::get('/', [AuctionController::class, 'index']);
    
    // View single auction details
    Route::get('/{id}', [AuctionController::class, 'show']);
    
    // List bids for an auction
    Route::get('/{id}/bids', [BidController::class, 'index']);
    
    // Get highest bid
    Route::get('/{id}/highest-bid', [BidController::class, 'highestBid']);
});

// Protected routes (Auth required)
Route::middleware(['auth:sanctum', 'role:admin,user'])->prefix('soom')->group(function () {
    
    // Auction management (for advertisers)
    Route::prefix('auctions')->group(function () {
        // Get auction details (protected)
        Route::get('/{id}', [AuctionController::class, 'show']);
        
        // Create auction
        Route::post('/', [AuctionController::class, 'store']);
        
        // Update auction (only draft/pending_payment)
        Route::put('/{id}', [AuctionController::class, 'update']);
        
        // Cancel auction (only before start)
        Route::delete('/{id}/cancel', [AuctionController::class, 'cancel']);
        
        // Close auction manually
        Route::put('/{id}/close', [AuctionController::class, 'close']);
        
        // Pay advertiser deposit
        Route::post('/{id}/pay-deposit', [AuctionController::class, 'payDeposit']);
        
        // Admin: Set/Update deposit values
        Route::put('/{id}/set-deposit', [AuctionController::class, 'setDeposit']);
    });
    
    // My auctions (as advertiser)
    Route::get('/my/auctions', [AuctionController::class, 'myAuctions']);
    
    // Bid management (for bidders)
    Route::prefix('auctions/{id}')->group(function () {
        // Get deposit info
        Route::get('/deposit-info', [BidController::class, 'getDepositInfo']);
        
        // Pay bidder deposit
        Route::post('/pay-bid-deposit', [BidController::class, 'payBidDeposit']);
        
        // Place a bid
        Route::post('/bid', [BidController::class, 'store']);
        
        // Increase existing bid
        Route::put('/bid', [BidController::class, 'increaseBid']);
    });
    
    // My bids (as bidder)
    Route::get('/my/bids', [BidController::class, 'myBids']);

    // ============= إيصالات الدفع (Payment Slips) ============
    Route::prefix('payment-slips')->group(function () {
        Route::post('/', [\App\Http\Controllers\Auction\PaymentSlipController::class, 'store']);
    });

    // جلب إيصالات مزاد أو مزايدة معينة
    Route::get('/auctions/{id}/payment-slips', [\App\Http\Controllers\Auction\PaymentSlipController::class, 'byAuction']);
    Route::get('/bids/{id}/payment-slips', [\App\Http\Controllers\Auction\PaymentSlipController::class, 'byBid']);
});
