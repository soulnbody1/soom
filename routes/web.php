<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Ad\AdController;

Route::get('/clear-cache', function () {
    Artisan::call('optimize:clear');
    return 'تم مسح الكاش بنجاح';
});
Route::get('/share/show/{id}', [AdController::class, 'sharePage'])->name('share.show');
Route::get('/open/soom', function () {
    return view('share.soom');
})->name('open.soom');

Route::get('/.well-known/assetlinks.json', function () {
    return response()->file(public_path('.well-known/assetlinks.json'));
});
