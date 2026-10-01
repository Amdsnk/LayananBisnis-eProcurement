<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\OidcController;
use App\Http\Controllers\TenderController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/health/ready', [HealthController::class, 'ready'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
Route::prefix('api/v1')->group(function () {
    Route::get('auth-config', [OidcController::class, 'config']);
    Route::get('oidc/start', [OidcController::class, 'start'])->middleware('throttle:10,1');
    Route::get('oidc/callback', [OidcController::class, 'callback'])->middleware('throttle:10,1');
    Route::get('csrf', [AuthController::class, 'csrf']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('webhooks/{id}', [IntegrationController::class, 'webhook'])->middleware('throttle:120,1');
    Route::middleware(['tenant', 'throttle:240,1'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('tenant', [AuthController::class, 'switch']);
        Route::get('dashboard', [DocumentController::class, 'dashboard']);
        Route::get('lookups', [AdminController::class, 'lookups']);
        Route::get('imports/{resource}/template', [ImportController::class, 'template']);
        Route::post('imports/{resource}/preview', [ImportController::class, 'preview']);
        Route::post('imports/{id}/commit', [ImportController::class, 'commit']);
        Route::post('exports', [ExportController::class, 'create']);
        Route::get('exports', [ExportController::class, 'list']);
        Route::get('exports/{id}/download', [ExportController::class, 'download']);
        Route::get('documents/export', [DocumentController::class, 'export']);
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{id}', [DocumentController::class, 'show']);
        Route::put('documents/{id}', [DocumentController::class, 'update']);
        Route::get('documents/{id}/pdf', [DocumentController::class, 'pdf']);
        Route::post('documents/{id}/actions/{action}', [DocumentController::class, 'action']);
        Route::post('documents/{id}/attachments', [AttachmentController::class, 'upload']);
        Route::get('attachments/{id}/download', [AttachmentController::class, 'download']);
        Route::post('tenders/{id}/publish', [TenderController::class, 'publish']);
        Route::post('tenders/{id}/bids', [TenderController::class, 'bid']);
        Route::get('tenders/{id}/bids', [TenderController::class, 'bids']);
        Route::post('tenders/{id}/open', [TenderController::class, 'open']);
        Route::post('tenders/{id}/evaluate', [TenderController::class, 'evaluate']);
        Route::post('tenders/{id}/award', [TenderController::class, 'award']);
        Route::get('admin/{table}', [AdminController::class, 'list']);
        Route::post('admin/{table}', [AdminController::class, 'save']);
        Route::post('admin/{table}/{id}/deactivate', [AdminController::class, 'deactivate']);
        Route::post('vendors/{id}/review', [AdminController::class, 'vendorAction']);
        Route::post('vendors/{id}/bank-change', [AdminController::class, 'changeBank']);
        Route::post('vendor-changes/{id}/review', [AdminController::class, 'reviewBank']);
        Route::post('integrations/{id}/toggle', [IntegrationController::class, 'toggle']);
        Route::post('outbox/{id}/replay', [IntegrationController::class, 'replay']);
    });
});
Route::get('/{path?}', function () {
    return response()->file(public_path('app/index.html'));
})->where('path', '^(?!api|health|up).*');
