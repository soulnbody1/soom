<?php

use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\Ad\AdController;
use App\Http\Controllers\Ad\AdReelViewController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\Location\CityController;
use App\Http\Controllers\Location\CountryController;
use App\Http\Controllers\Location\StateController;
use App\Http\Controllers\Attribute\AttributeController;
use Illuminate\Support\Facades\Route;



Route::prefix('soom')->group(function () {

    // ✅ Categories
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('{category}', [CategoryController::class, 'show']);
    });

    // ✅ Ads
    Route::prefix('ads')->group(function () {
        Route::get('/', [AdController::class, 'filter']);
        Route::get('/reels', [AdReelViewController::class, 'reels']);
        Route::get('/reels/{id}', [AdReelViewController::class, 'ReelsForCategories']);
        Route::get('/search', [AdController::class, 'search']);
        Route::get('/{id}', [AdController::class, 'show']);
        Route::get('/category/{id}', [AdController::class, 'adsByCategoryWithChildren']);
    });

    // ✅ Countries
    Route::prefix('countries')->group(function () {
        Route::get('/', [CountryController::class, 'index']);
        Route::get('{id}', [CountryController::class, 'show']);
    });

    // ✅ States
    Route::prefix('states')->group(function () {
        Route::get('/', [StateController::class, 'index']);
        Route::get('{id}', [StateController::class, 'show']);
    });

    // ✅ Citys
    Route::prefix('citys')->group(function () {
        Route::get('/', [CityController::class, 'index']);
    });

    // ✅ banner
    Route::prefix('banners')->group(function () {
        Route::get('/', [BannerController::class, 'index']);
    });


    Route::prefix('attributes')->group(function () {
        Route::get('/by-category', [AttributeController::class, 'getAttributesByCategory']);
    });

    // ✅ Homepage
    Route::get('home', [AdController::class, 'home']);
});
