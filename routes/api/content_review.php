<?php

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Http\Controllers\ContentReview\ContentReviewController;
use App\Http\Controllers\ContentReview\ContentReviewHealthController;
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
        ->whereIn('subjectType', $subjectTypes);
    Route::post('/{subjectType}/{subjectId}/force-manual', [ContentReviewController::class, 'forceManual'])
        ->whereIn('subjectType', $subjectTypes);

    Route::get('/{contentReview}', [ContentReviewController::class, 'show']);
    Route::post('/{contentReview}/retry', [ContentReviewController::class, 'retry']);
    Route::post('/{contentReview}/cancel', [ContentReviewController::class, 'cancel']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/content-review')->group(function () {
    Route::get('/settings', [ContentReviewSettingsController::class, 'show']);
    Route::get('/settings/versions', [ContentReviewSettingsController::class, 'index']);
    Route::post('/settings', [ContentReviewSettingsController::class, 'store']);

    Route::get('/policies', [ContentReviewPolicyController::class, 'index']);
    Route::get('/policies/active', [ContentReviewPolicyController::class, 'active']);
    Route::get('/policies/{contentReviewPolicy}', [ContentReviewPolicyController::class, 'show']);
    Route::post('/policies', [ContentReviewPolicyController::class, 'store']);

    Route::get('/health', [ContentReviewHealthController::class, 'show']);
    Route::post('/provider/test', [ContentReviewHealthController::class, 'test'])
        ->middleware('throttle:content-review-provider-test');
});
