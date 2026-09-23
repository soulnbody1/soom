<?php

use App\Http\Controllers\Auction\AuctionAuditController;
use App\Http\Controllers\Auction\AuctionConfigurationController;
use App\Http\Controllers\Auction\AuctionController;
use App\Http\Controllers\Auction\AuctionDashboardController;
use App\Http\Controllers\Auction\AuctionDisputeController;
use App\Http\Controllers\Auction\AuctionOperationalSettingsController;
use App\Http\Controllers\Auction\AuctionTermsController;
use App\Http\Controllers\Auction\BidController;
use App\Http\Controllers\Auction\BillPresentmentController;
use App\Http\Controllers\Auction\MyParticipationController;
use App\Http\Controllers\Auction\OnlinePaymentController;
use App\Http\Controllers\Auction\PaymentMethodController;
use App\Http\Controllers\Auction\PaymentProviderController;
use App\Http\Controllers\Auction\PaymentRecordController;
use App\Http\Controllers\Auction\PaymentSubmissionController;
use App\Http\Controllers\Auction\PaymentWebhookController;
use App\Http\Controllers\Auction\PayoutDestinationController;
use App\Http\Controllers\Auction\RefundController;
use App\Http\Controllers\Auction\SellerPayoutController;
use App\Http\Controllers\Auction\SupportContactController;
use App\Http\Middleware\AttachServerTime;
use App\Http\Middleware\OptionalSanctumAuthentication;
use Illuminate\Support\Facades\Route;

Route::prefix('auctions')->middleware([OptionalSanctumAuthentication::class, AttachServerTime::class])->group(function () {
    Route::get('/', [AuctionController::class, 'index']);
    Route::get('/{auction}', [AuctionController::class, 'show']);
    Route::get('/{auction}/bids', [BidController::class, 'index']);
    Route::get('/{auction}/terms', [AuctionTermsController::class, 'showForAuction']);
});

Route::post('webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'])
    ->withoutMiddleware('market')
    ->middleware('throttle:payment-webhooks')
    ->where('provider', '[A-Za-z0-9_-]+');

Route::post('webhooks/payments/{provider}/bills', [BillPresentmentController::class, 'handle'])
    ->withoutMiddleware('market')
    ->middleware('throttle:payment-bill-queries')
    ->where('provider', '[A-Za-z0-9_-]+');

Route::prefix('soom')->group(function () {
    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::get('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'show']);
    Route::get('/auction-terms', [AuctionTermsController::class, 'index']);
    Route::get('/support-contact', [SupportContactController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'role:admin,user', AttachServerTime::class])->prefix('soom')->group(function () {
    Route::get('/my/auctions', [AuctionController::class, 'mine'])->middleware('account_global');
    Route::get('/my/participations', [MyParticipationController::class, 'index'])->middleware('account_global');
    Route::get('/my/refunds', [MyParticipationController::class, 'refunds'])->middleware('account_global');
    Route::get('/my/bids', [BidController::class, 'mine'])->middleware('account_global');
    Route::get('/my/payouts', [SellerPayoutController::class, 'mine'])->middleware('account_global');
    Route::get('/my/payouts/{sellerPayout}', [SellerPayoutController::class, 'showMine'])->middleware('account_global');
    Route::get('/my/payouts/{sellerPayout}/proof-url', [SellerPayoutController::class, 'proofUrl'])->middleware('account_global');
    Route::get('/my/payout-destinations', [PayoutDestinationController::class, 'index']);
    Route::post('/my/payout-destinations', [PayoutDestinationController::class, 'store']);
    Route::put('/my/payout-destinations/{payoutDestination}', [PayoutDestinationController::class, 'update']);
    Route::delete('/my/payout-destinations/{payoutDestination}', [PayoutDestinationController::class, 'destroy']);
    Route::get('/payment-submissions/{paymentSubmission}/receipt-url', [PaymentSubmissionController::class, 'receiptUrl'])->middleware('account_global');
    Route::get('/payments/{paymentTransaction}', [OnlinePaymentController::class, 'show'])->middleware('account_global');

    Route::prefix('auctions')->group(function () {
        Route::post('/', [AuctionController::class, 'store']);
        Route::patch('/{auction}', [AuctionController::class, 'update']);
        Route::post('/{auction}/reopen', [AuctionController::class, 'reopen']);
        Route::post('/{auction}/submit-review', [AuctionController::class, 'submitForReview']);
        Route::post('/{auction}/seller-deposit', [AuctionController::class, 'submitSellerDeposit']);
        Route::post('/{auction}/end-now', [AuctionController::class, 'endEarly'])->middleware('throttle:auction-participation');
        Route::post('/{auction}/register', [AuctionController::class, 'register'])->middleware('throttle:auction-participation');
        Route::post('/{auction}/accept-terms', [AuctionController::class, 'acceptTerms'])->middleware('throttle:auction-participation');
        Route::post('/{auction}/bidder-deposit', [AuctionController::class, 'submitBidderDeposit']);
        Route::post('/{auction}/bids', [BidController::class, 'store'])->middleware('throttle:auction-bids');
        Route::post('/{auction}/winner-payment', [AuctionController::class, 'submitWinnerPayment']);
        Route::post('/{auction}/confirm-handover', [AuctionController::class, 'confirmSellerHandover'])->middleware('throttle:auction-participation');
        Route::post('/{auction}/confirm-receipt', [AuctionController::class, 'confirmWinnerReceipt']);
        Route::post('/{auction}/disputes', [AuctionController::class, 'openDispute'])->middleware('throttle:auction-participation');
        Route::get('/{auction}/disputes', [AuctionDisputeController::class, 'forAuction']);
        Route::get('/{auction}/disputes/{dispute}', [AuctionDisputeController::class, 'showForAuction']);
        Route::get('/{auction}/payment-methods', [PaymentMethodController::class, 'forAuction']);
        Route::post('/{auction}/payments', [OnlinePaymentController::class, 'store'])->middleware('throttle:payment-intents');
        Route::delete('/{auction}', [AuctionController::class, 'cancel']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/auctions')->group(function () {
    Route::get('/', [AuctionController::class, 'all']);
    Route::get('/dashboard', [AuctionDashboardController::class, 'index']);
    Route::get('/operational-settings', [AuctionOperationalSettingsController::class, 'show']);
    Route::get('/support-contact', [SupportContactController::class, 'edit'])->middleware('admin_market_required');
    Route::post('/support-contact', [SupportContactController::class, 'store'])->middleware('admin_market_required');
    Route::get('/terms/{terms}', [AuctionTermsController::class, 'show']);
    Route::get('/terms', [AuctionTermsController::class, 'adminIndex']);
    Route::post('/terms', [AuctionTermsController::class, 'store'])->middleware('admin_market_required');
    Route::get('/configuration-versions', [AuctionConfigurationController::class, 'index']);
    Route::get('/configuration-versions/{configurationVersion}', [AuctionConfigurationController::class, 'show']);
    Route::post('/configuration-versions', [AuctionConfigurationController::class, 'store'])->middleware('admin_market_required');
    Route::get('/disputes', [AuctionDisputeController::class, 'index']);
    Route::get('/payment-methods', [PaymentMethodController::class, 'all']);
    Route::get('/payment-providers', [PaymentProviderController::class, 'index']);
    Route::get('/payment-method-options', [PaymentProviderController::class, 'options']);
    Route::post('/payment-providers/{provider}/test', [PaymentProviderController::class, 'test'])->where('provider', '[A-Za-z0-9_-]+')->middleware('admin_market_required');
    Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->middleware('admin_market_required');
    Route::put('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->middleware('admin_market_required');
    Route::get('/refunds', [RefundController::class, 'index']);
    Route::get('/refunds/{refund}/proof-url', [RefundController::class, 'proofUrl']);
    Route::post('/refunds/{refund}/confirm', [RefundController::class, 'confirm'])->middleware('admin_market_required');
    Route::post('/refunds/{refund}/cancel', [RefundController::class, 'cancel'])->middleware('admin_market_required');
    Route::get('/payouts', [SellerPayoutController::class, 'index']);
    Route::get('/payouts/summary', [SellerPayoutController::class, 'summary']);
    Route::get('/payouts/{sellerPayout}', [SellerPayoutController::class, 'show']);
    Route::get('/payouts/{sellerPayout}/proof-url', [SellerPayoutController::class, 'proofUrl']);
    Route::post('/payouts/{sellerPayout}/start-processing', [SellerPayoutController::class, 'startProcessing'])->middleware('admin_market_required');
    Route::post('/payouts/{sellerPayout}/mark-paid', [SellerPayoutController::class, 'markPaid'])->middleware('admin_market_required');
    Route::post('/payouts/{sellerPayout}/mark-failed', [SellerPayoutController::class, 'markFailed'])->middleware('admin_market_required');
    Route::post('/payouts/{sellerPayout}/hold', [SellerPayoutController::class, 'hold'])->middleware('admin_market_required');
    Route::post('/payouts/{sellerPayout}/release', [SellerPayoutController::class, 'release'])->middleware('admin_market_required');
    Route::post('/{auction}/end-now', [AuctionController::class, 'endEarly'])->middleware('admin_market_required');
    Route::post('/{auction}/review', [AuctionController::class, 'review'])->middleware('admin_market_required');
    Route::post('/{auction}/disputes/{auctionDispute}/resolve', [AuctionController::class, 'resolveDispute'])->middleware('admin_market_required');
    Route::post('/{auction}/winner-default', [AuctionController::class, 'markWinnerDefaulted'])->middleware('admin_market_required');
    Route::get('/{auction}/participants', [AuctionController::class, 'participants']);
    Route::post('/{auction}/participants/{participant}/block', [AuctionController::class, 'blockParticipant'])->middleware('admin_market_required');
    Route::post('/{auction}/participants/{participant}/unblock', [AuctionController::class, 'unblockParticipant'])->middleware('admin_market_required');
    Route::get('/{auction}/activity', [AuctionAuditController::class, 'activity']);
    Route::get('/{auction}/status-history', [AuctionAuditController::class, 'statusHistory']);
    Route::get('/payments', [PaymentRecordController::class, 'index']);
    Route::get('/payments/{record}', [PaymentRecordController::class, 'show']);
    Route::post('/payments/{record}/refund', [PaymentRecordController::class, 'refund'])->middleware('admin_market_required');
    Route::get('/payment-submissions', [PaymentSubmissionController::class, 'index']);
    Route::get('/payment-submissions/{paymentSubmission}/receipt-url', [PaymentSubmissionController::class, 'receiptUrl']);
    Route::post('/payment-submissions/{paymentSubmission}/review', [PaymentSubmissionController::class, 'review'])->middleware('admin_market_required');
    Route::get('/{auction}', [AuctionController::class, 'showForAdmin']);
});
