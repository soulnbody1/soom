<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Ad\AdController;
use App\Http\Controllers\Auction\PaymentReturnController;

Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/clear-cache', function () {
    Artisan::call('optimize:clear');
    return 'تم مسح الكاش بنجاح';
});
Route::get('/share/show/{id}', [AdController::class, 'sharePage'])->name('share.show');
Route::get('/open/soom', function () {
    return view('share.soom');
})->name('open.soom');

Route::get('/payments/return/{paymentTransaction}', [PaymentReturnController::class, 'show'])
    ->where('paymentTransaction', '[0-9A-Za-z]{26}')
    ->name('payments.return');

Route::get('/.well-known/assetlinks.json', function () {
    return response()->file(public_path('.well-known/assetlinks.json'));
});
