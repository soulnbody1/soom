<?php

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/register', [RegistrationController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('/forget-password', [PasswordResetController::class, 'sendCode'])->middleware('throttle:auth-otp-request');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth-otp-verify');
    Route::post('/refreshToken', [SessionController::class, 'refresh'])->middleware('throttle:auth-refresh');
    Route::post('/verify-otp', [RegistrationController::class, 'verify'])->middleware('throttle:auth-otp-verify');
});
