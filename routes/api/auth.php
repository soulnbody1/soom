<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forget-password', [AuthController::class, 'forget_password']);
    Route::post('/reset-password', [AuthController::class, 'reset_password']);
    Route::post('/refreshToken', [AuthController::class, 'refreshToken']);
    Route::post('/verify-otp', [AuthController::class, 'verify_otp']);
});
