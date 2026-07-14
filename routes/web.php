<?php

use App\Http\Controllers\ApprovalLevelController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\PaymentRequestController;
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

        // Invoice Log — submission, review, ERP posting / query
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::post('/invoices/{invoice}', [InvoiceController::class, 'update']); // POST for multipart updates
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy']);
        Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);

        Route::middleware('role:finance,admin')->group(function () {
            Route::post('/invoices/{invoice}/post', [InvoiceController::class, 'post']);
            Route::post('/invoices/{invoice}/query', [InvoiceController::class, 'raiseQuery']);
        });

        // documents
        Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

        // Payment requests (PRF) + approval chain
        Route::get('/payment-requests/eligible', [PaymentRequestController::class, 'eligible'])->middleware('role:finance,admin');
        Route::get('/payment-requests/pending', [PaymentRequestController::class, 'pending']);
        Route::get('/payment-requests', [PaymentRequestController::class, 'index']);
        Route::post('/payment-requests', [PaymentRequestController::class, 'store'])->middleware('role:finance,admin');
        Route::get('/payment-requests/{paymentRequest}', [PaymentRequestController::class, 'show']);
        Route::post('/payment-requests/{paymentRequest}/approve', [PaymentRequestController::class, 'approve']);
        Route::post('/payment-requests/{paymentRequest}/reject', [PaymentRequestController::class, 'reject']);
        Route::post('/payment-requests/{paymentRequest}/mark-paid', [PaymentRequestController::class, 'markPaid'])->middleware('role:finance,admin');

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
