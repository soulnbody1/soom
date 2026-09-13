<?php

use Illuminate\Support\Facades\Route;

Route::get('/status', function () {
    if (config('app.api_maintenance')) {
        return response()->json([
            'status' => 'maintenance',
            'message' => 'الموقع تحت الصيانة الآن، برجاء المحاولة لاحقاً.',
        ], 503);
    }

    return response()->json([
        'status' => 'ok',
        'message' => 'الموقع يعمل الآن.',
    ]);
});
Route::middleware(['api_maintenance'])->group(function () {
    require __DIR__.'/api/auth.php';
    require __DIR__.'/api/admin.php';
    require __DIR__.'/api/user.php';
    require __DIR__.'/api/guest.php';
    require __DIR__.'/api/auction.php';
    require __DIR__.'/api/content_review.php';
    require __DIR__.'/api/seller_rating.php';
});
