<?php

use App\Http\Controllers\Ad\AdController;
use App\Http\Controllers\Ad\AdListingController;
use App\Http\Controllers\Ad\AdReelViewController;
use App\Http\Controllers\Ad\AdSearchController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Attribute\AttributeController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\CharitySystemController;
use App\Http\Controllers\Location\CityController;
use App\Http\Controllers\Location\CountryController;
use App\Http\Controllers\Location\StateController;
use Illuminate\Support\Facades\Route;

Route::prefix('soom')->group(function () {
    Route::prefix('categories')->middleware('throttle:catalog-public')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('{category}', [CategoryController::class, 'show'])->whereNumber('category');
    });

    Route::prefix('ads')->group(function () {
        Route::get('/search', AdSearchController::class)->middleware('throttle:ads-search');

        Route::middleware('throttle:ads-public')->group(function () {
            Route::get('/', [AdListingController::class, 'index']);
            Route::get('/reels', [AdReelViewController::class, 'reels']);
            Route::get('/reels/{id}', [AdReelViewController::class, 'ReelsForCategories']);
            Route::get('/{id}', [AdController::class, 'show']);
            Route::get('/category/{id}', [AdListingController::class, 'byCategory']);
        });
    });

    Route::prefix('countries')->middleware('throttle:catalog-public')->group(function () {
        Route::get('/', [CountryController::class, 'index']);
        Route::get('{id}', [CountryController::class, 'show']);
    });

    Route::prefix('states')->middleware('throttle:catalog-public')->group(function () {
        Route::get('/', [StateController::class, 'index']);
        Route::get('{id}', [StateController::class, 'show']);
    });

    Route::prefix('citys')->middleware('throttle:catalog-public')->group(function () {
        Route::get('/', [CityController::class, 'index']);
    });

    Route::prefix('banners')->middleware('throttle:catalog-public')->group(function () {
        Route::get('/', [BannerController::class, 'index']);
    });

    Route::prefix('charity_system')->middleware('throttle:catalog-public')->group(function () {
        Route::get('/', [CharitySystemController::class, 'index']);
    });

    Route::prefix('announcements')->middleware('throttle:catalog-public')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index']);
    });

    Route::prefix('attributes')->middleware('throttle:catalog-public')->group(function () {
        Route::get('/by-category', [AttributeController::class, 'getAttributesByCategory']);
    });

    Route::get('home', [AdListingController::class, 'home'])->middleware('throttle:ads-public');
});
