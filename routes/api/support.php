<?php

use App\Http\Controllers\Admin\Support\AdminSupportAgentController;
use App\Http\Controllers\Admin\Support\AdminSupportConversationController;
use App\Http\Controllers\Admin\Support\AdminSupportMessageController;
use App\Http\Controllers\Admin\Support\AdminSupportReadController;
use App\Http\Controllers\Admin\Support\AdminSupportTicketController;
use App\Http\Controllers\Support\SupportCategoryController;
use App\Http\Controllers\Support\SupportConversationController;
use App\Http\Controllers\Support\SupportMessageController;
use App\Http\Controllers\Support\SupportReadController;
use App\Http\Controllers\Support\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::pattern('ticket', '[0-9A-HJKMNP-TV-Z]{26}');

Route::middleware(['auth:sanctum', 'role:admin,user', 'throttle:support-read'])->prefix('soom/support')->group(function () {
    Route::get('/categories', SupportCategoryController::class);
    Route::get('/tickets', [SupportTicketController::class, 'index']);
    Route::post('/tickets', [SupportTicketController::class, 'store'])->middleware('throttle:support-create');
    Route::get('/tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::get('/tickets/{ticket}/conversation', SupportConversationController::class);
    Route::get('/tickets/{ticket}/messages', [SupportMessageController::class, 'index']);
    Route::post('/tickets/{ticket}/messages', [SupportMessageController::class, 'store'])->middleware('throttle:support-message');
    Route::post('/tickets/{ticket}/read', SupportReadController::class)->middleware('throttle:support-message');
    Route::post('/tickets/{ticket}/resolve', [SupportTicketController::class, 'resolve'])->middleware('throttle:support-message');
    Route::post('/tickets/{ticket}/reopen', [SupportTicketController::class, 'reopen'])->middleware('throttle:support-message');
});

Route::middleware(['auth:sanctum', 'role:admin', 'throttle:support-read'])->prefix('admin/support')->group(function () {
    Route::get('/agents', AdminSupportAgentController::class);
    Route::get('/tickets', [AdminSupportTicketController::class, 'index']);
    Route::get('/summary', [AdminSupportTicketController::class, 'summary']);
    Route::get('/tickets/{ticket}', [AdminSupportTicketController::class, 'show']);
    Route::get('/tickets/{ticket}/conversation', AdminSupportConversationController::class);
    Route::patch('/tickets/{ticket}', [AdminSupportTicketController::class, 'update'])->middleware(['admin_market_required', 'throttle:support-admin-write']);
    Route::get('/tickets/{ticket}/messages', [AdminSupportMessageController::class, 'index']);
    Route::post('/tickets/{ticket}/messages', [AdminSupportMessageController::class, 'store'])->middleware(['admin_market_required', 'throttle:support-admin-write']);
    Route::post('/tickets/{ticket}/internal-notes', [AdminSupportMessageController::class, 'internalNote'])->middleware(['admin_market_required', 'throttle:support-admin-write']);
    Route::post('/tickets/{ticket}/read', AdminSupportReadController::class)->middleware(['admin_market_required', 'throttle:support-admin-write']);
});
