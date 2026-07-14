<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\ApprovalLevelController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API (session-authenticated, same-origin SPA)
|--------------------------------------------------------------------------
*/
Route::prefix('api')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/meta', [MetaController::class, 'index']);
        Route::get('/approvers', [MetaController::class, 'approvers']);

        Route::get('/dashboard', [DashboardController::class, 'index']);

        // invoice payment requests
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::post('/invoices/{invoice}', [InvoiceController::class, 'update']); // POST for multipart updates
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy']);
        Route::post('/invoices/{invoice}/submit', [InvoiceController::class, 'submit']);
        Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);

        // documents
        Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

        // approvals
        Route::get('/approvals/pending', [ApprovalController::class, 'pending']);
        Route::post('/invoices/{invoice}/approve', [ApprovalController::class, 'approve']);
        Route::post('/invoices/{invoice}/reject', [ApprovalController::class, 'reject']);

        // payment processing (finance)
        Route::middleware('role:finance,admin')->group(function () {
            Route::get('/payments/queue', [PaymentController::class, 'queue']);
            Route::post('/invoices/{invoice}/schedule', [PaymentController::class, 'schedule']);
            Route::post('/invoices/{invoice}/mark-paid', [PaymentController::class, 'markPaid']);
        });

        // reports (data scoped by role visibility)
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/export', [ReportController::class, 'export']);

        // administration
        Route::middleware('role:admin')->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/audit-logs/actions', [AuditLogController::class, 'actions']);

            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::put('/users/{user}', [UserController::class, 'update']);

            Route::get('/approval-levels', [ApprovalLevelController::class, 'index']);
            Route::post('/approval-levels', [ApprovalLevelController::class, 'store']);
            Route::put('/approval-levels/{approvalLevel}', [ApprovalLevelController::class, 'update']);
            Route::delete('/approval-levels/{approvalLevel}', [ApprovalLevelController::class, 'destroy']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| SPA catch-all
|--------------------------------------------------------------------------
*/
Route::view('/{any?}', 'app')->where('any', '^(?!api).*$');
