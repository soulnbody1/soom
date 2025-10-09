<?php

use App\Http\Controllers\Ad\AdController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Attribute\AttributeController;
use App\Http\Controllers\Attribute\AttributeOptionController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\Location\CountryController;
use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\Location\CityController;
use App\Http\Controllers\Location\StateController;
use App\Http\Controllers\User\ProfileController;
use Illuminate\Support\Facades\Route;


Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('countries', CountryController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('states', StateController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('citys', CityController::class)->only(['store', 'update', 'destroy']);

    // ============= السمات والحقول ============
    Route::prefix('attributes')->group(function () {
        Route::post('/', [AttributeController::class, 'store']);
        Route::put('/{id}', [AttributeController::class, 'update']);
        Route::delete('/{id}', [AttributeController::class, 'destroy']);//
        Route::get('/{id}', [AttributeController::class, 'getOptionsByAttributeId']);
        Route::post('/sync-attributes', [AttributeController::class, 'syncAttributesToCategory']);
        Route::post('/exclude', [AttributeController::class, 'excludeAttributeFromCategory']);
        Route::post('/include', [AttributeController::class, 'includeAttributeBack']);
    });

    // ============= ادارة المستخدمين  ============
    Route::prefix('users')->group(function () {
        Route::get('/', [ProfileController::class,'users']);
        Route::get('/analytics', [ProfileController::class,'analytics']);
        Route::get('/search', [ProfileController::class, 'search']);
        Route::delete('/force-delete/{id}', [ProfileController::class, 'destroybyadmin']);
        Route::put('/toggle-block/{id}', [ProfileController::class, 'toggleBlock']);
    });

    //  banner

    Route::prefix('banners')->group(function () {
        Route::get('/', [BannerController::class, 'indexforadmin']);
        Route::get('/{id}', [BannerController::class, 'show']);
        Route::post('/', [BannerController::class, 'store']);
        Route::put('/{id}', [BannerController::class, 'update']);
        Route::delete('/{id}', [BannerController::class, 'destroy']);
    });


    Route::prefix('announcements')->group(function () {
        Route::get('/', [AnnouncementController::class, 'indexforadmin']);
        Route::get('/{id}', [AnnouncementController::class, 'show']);
        Route::post('/', [AnnouncementController::class, 'store']);
        Route::post('/{id}', [AnnouncementController::class, 'update']);
        Route::delete('/{id}', [AnnouncementController::class, 'destroy']);
    });


    // ============= ادارة الاعلانات  ============
    Route::prefix('ads')->group(function () {
        Route::get('/', [AdController::class, 'ads']);
        Route::delete('/force-delete/{id}', [AdController::class, 'destroybyadmin']);
        Route::put('/toggle-block/{id}', [AdController::class, 'toggleBlock']);
    });


    // ============= قيم السمات ============
    Route::prefix('attribute-options')->group(function () {
        Route::post('/', [AttributeOptionController::class, 'store']);
        Route::put('/{id}', [AttributeOptionController::class, 'update']);
        Route::delete('/{id}', [AttributeOptionController::class, 'destroy']);
    });
});
