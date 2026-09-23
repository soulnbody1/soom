<?php

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Http\Controllers\ContentReview\ContentReviewController;
use App\Http\Controllers\ContentReview\ContentReviewHealthController;
use App\Http\Controllers\ContentReview\ContentReviewMetricsController;
use App\Http\Controllers\ContentReview\ContentReviewPolicyController;
use App\Http\Controllers\ContentReview\ContentReviewSettingsController;
use Illuminate\Support\Facades\Route;

$subjectTypes = array_column(ReviewableSubjectType::cases(), 'value');

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/content-reviews')->group(function () use ($subjectTypes) {
    Route::get('/{subjectType}/{subjectId}', [ContentReviewController::class, 'history'])
        ->whereIn('subjectType', $subjectTypes);
    Route::get('/{subjectType}/{subjectId}/current', [ContentReviewController::class, 'current'])
        ->whereIn('subjectType', $subjectTypes);
    Route::post('/{subjectType}/{subjectId}/run', [ContentReviewController::class, 'run'])
        ->middleware('admin_market_required')
        ->whereIn('subjectType', $subjectTypes);
    Route::post('/{subjectType}/{subjectId}/force-manual', [ContentReviewController::class, 'forceManual'])
        ->middleware('admin_market_required')
        ->whereIn('subjectType', $subjectTypes);

    Route::get('/{contentReview}', [ContentReviewController::class, 'show']);
    Route::post('/{contentReview}/retry', [ContentReviewController::class, 'retry'])->middleware('admin_market_required');
    Route::post('/{contentReview}/cancel', [ContentReviewController::class, 'cancel'])->middleware('admin_market_required');
    Route::post('/{contentReview}/decide', [ContentReviewController::class, 'decide'])->middleware('admin_market_required');
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/content-review')->group(function () {
    Route::get('/settings', [ContentReviewSettingsController::class, 'show'])->middleware('admin_market_required');
    Route::get('/settings/versions', [ContentReviewSettingsController::class, 'index'])->middleware('admin_market_required');
    Route::post('/settings', [ContentReviewSettingsController::class, 'store'])->middleware('admin_market_required');

    Route::get('/policies', [ContentReviewPolicyController::class, 'index'])->middleware('admin_market_required');
    Route::get('/policies/active', [ContentReviewPolicyController::class, 'active'])->middleware('admin_market_required');
    Route::get('/policies/{contentReviewPolicy}', [ContentReviewPolicyController::class, 'show'])->middleware('admin_market_required');
    Route::post('/policies', [ContentReviewPolicyController::class, 'store'])->middleware('admin_market_required');

    Route::get('/metrics', [ContentReviewMetricsController::class, 'show'])->middleware('admin_market_required');

    Route::get('/health', [ContentReviewHealthController::class, 'show'])->middleware('admin_market_required');
    Route::post('/provider/test', [ContentReviewHealthController::class, 'test'])
        ->middleware(['admin_market_required', 'throttle:content-review-provider-test']);
});
