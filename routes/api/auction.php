<?php

use App\Http\Controllers\Auction\AuctionAuditController;
use App\Http\Controllers\Auction\AuctionConfigurationController;
use App\Http\Controllers\Auction\AuctionController;
use App\Http\Controllers\Auction\AuctionDisputeController;
use App\Http\Controllers\Auction\AuctionTermsController;
use App\Http\Controllers\Auction\BidController;
use App\Http\Controllers\Auction\PaymentMethodController;
use App\Http\Controllers\Auction\PaymentSubmissionController;
use App\Http\Controllers\Auction\PayoutDestinationController;
use App\Http\Controllers\Auction\RefundController;
use App\Http\Controllers\Auction\SellerPayoutController;
use App\Http\Middleware\OptionalSanctumAuthentication;
use Illuminate\Support\Facades\Route;

Route::prefix('auctions')->middleware(OptionalSanctumAuthentication::class)->group(function () {
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
    Route::get('/my/payouts', [SellerPayoutController::class, 'mine']);
    Route::get('/my/payouts/{sellerPayout}', [SellerPayoutController::class, 'showMine']);
    Route::get('/my/payouts/{sellerPayout}/proof-url', [SellerPayoutController::class, 'proofUrl']);
    Route::get('/my/payout-destinations', [PayoutDestinationController::class, 'index']);
    Route::post('/my/payout-destinations', [PayoutDestinationController::class, 'store']);
    Route::put('/my/payout-destinations/{payoutDestination}', [PayoutDestinationController::class, 'update']);
    Route::get('/payment-submissions/{paymentSubmission}/receipt-url', [PaymentSubmissionController::class, 'receiptUrl']);

    Route::prefix('auctions')->group(function () {
        Route::post('/', [AuctionController::class, 'store']);
        Route::post('/{auction}/submit-review', [AuctionController::class, 'submitForReview']);
        Route::post('/{auction}/seller-deposit', [AuctionController::class, 'submitSellerDeposit']);
        Route::post('/{auction}/register', [AuctionController::class, 'register']);
        Route::post('/{auction}/accept-terms', [AuctionController::class, 'acceptTerms']);
        Route::post('/{auction}/bidder-deposit', [AuctionController::class, 'submitBidderDeposit']);
        Route::post('/{auction}/bids', [BidController::class, 'store']);
        Route::post('/{auction}/winner-payment', [AuctionController::class, 'submitWinnerPayment']);
        Route::post('/{auction}/confirm-handover', [AuctionController::class, 'confirmSellerHandover']);
        Route::post('/{auction}/confirm-receipt', [AuctionController::class, 'confirmWinnerReceipt']);
        Route::post('/{auction}/disputes', [AuctionController::class, 'openDispute']);
        Route::delete('/{auction}', [AuctionController::class, 'cancel']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/auctions')->group(function () {
    Route::get('/', [AuctionController::class, 'all']);
    Route::get('/terms/{terms}', [AuctionTermsController::class, 'show']);
    Route::post('/terms', [AuctionTermsController::class, 'store']);
    Route::get('/configuration-versions', [AuctionConfigurationController::class, 'index']);
    Route::get('/configuration-versions/{configurationVersion}', [AuctionConfigurationController::class, 'show']);
    Route::post('/configuration-versions', [AuctionConfigurationController::class, 'store']);
    Route::get('/disputes', [AuctionDisputeController::class, 'index']);
    Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::put('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update']);
    Route::get('/refunds', [RefundController::class, 'index']);
    Route::post('/refunds/{refund}/confirm', [RefundController::class, 'confirm']);
    Route::post('/refunds/{refund}/cancel', [RefundController::class, 'cancel']);
    Route::get('/payouts', [SellerPayoutController::class, 'index']);
    Route::get('/payouts/summary', [SellerPayoutController::class, 'summary']);
    Route::get('/payouts/{sellerPayout}', [SellerPayoutController::class, 'show']);
    Route::get('/payouts/{sellerPayout}/proof-url', [SellerPayoutController::class, 'proofUrl']);
    Route::post('/payouts/{sellerPayout}/start-processing', [SellerPayoutController::class, 'startProcessing']);
    Route::post('/payouts/{sellerPayout}/mark-paid', [SellerPayoutController::class, 'markPaid']);
    Route::post('/payouts/{sellerPayout}/mark-failed', [SellerPayoutController::class, 'markFailed']);
    Route::post('/payouts/{sellerPayout}/hold', [SellerPayoutController::class, 'hold']);
    Route::post('/payouts/{sellerPayout}/release', [SellerPayoutController::class, 'release']);
    Route::post('/{auction}/review', [AuctionController::class, 'review']);
    Route::post('/{auction}/disputes/{auctionDispute}/resolve', [AuctionController::class, 'resolveDispute']);
    Route::post('/{auction}/winner-default', [AuctionController::class, 'markWinnerDefaulted']);
    Route::get('/{auction}/participants', [AuctionController::class, 'participants']);
    Route::get('/{auction}/activity', [AuctionAuditController::class, 'activity']);
    Route::get('/{auction}/status-history', [AuctionAuditController::class, 'statusHistory']);
    Route::get('/payment-submissions', [PaymentSubmissionController::class, 'index']);
    Route::get('/payment-submissions/{paymentSubmission}/receipt-url', [PaymentSubmissionController::class, 'receiptUrl']);
    Route::post('/payment-submissions/{paymentSubmission}/review', [PaymentSubmissionController::class, 'review']);
});
