<?php

use App\Http\Controllers\Ad\AdSharePageController;
use App\Http\Controllers\Auction\PaymentReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});
Route::get('/share/show/{ad}', AdSharePageController::class)->whereUlid('ad')->name('share.show');
Route::get('/open/soom', function () {
    return view('share.soom');
})->name('open.soom');

Route::get('/payments/return/{paymentTransaction}', [PaymentReturnController::class, 'show'])
    ->where('paymentTransaction', '[0-9A-Za-z]{26}')
    ->name('payments.return');

Route::get('/.well-known/assetlinks.json', function () {
    return response()->file(public_path('.well-known/assetlinks.json'));
});
