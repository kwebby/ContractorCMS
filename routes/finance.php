<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use App\Http\Controllers\FinanceController;
use App\Http\Controllers\WorkforceController;
use Illuminate\Support\Facades\Route;

Route::post('/api/v1/payments/webhooks/stripe', [FinanceController::class, 'stripeWebhook'])->middleware('throttle:120,1')->name('payments.stripe.webhook');
Route::post('/api/v1/payments/webhooks/paypal', [FinanceController::class, 'paypalWebhook'])->middleware('throttle:120,1')->name('payments.paypal.webhook');

Route::middleware('auth')->prefix('api/v1')->group(function () {
    Route::get('estimates', [FinanceController::class, 'estimates']);
    Route::post('estimates', [FinanceController::class, 'createEstimate']);
    Route::get('estimates/{id}', [FinanceController::class, 'estimate']);
    Route::put('estimates/{id}', [FinanceController::class, 'reviseEstimate']);
    Route::post('estimates/{id}/send', [FinanceController::class, 'sendEstimate']);
    Route::post('estimates/{id}/accept', [FinanceController::class, 'acceptEstimate']);
    Route::post('estimates/{id}/duplicate', [FinanceController::class, 'duplicateEstimate']);
    Route::get('invoices', [FinanceController::class, 'invoices']);
    Route::post('invoices', [FinanceController::class, 'createInvoice']);
    Route::get('invoices/{id}', [FinanceController::class, 'invoice']);
    Route::put('invoices/{id}', [FinanceController::class, 'reviseInvoice']);
    Route::post('invoices/{id}/issue', [FinanceController::class, 'issueInvoice']);
    Route::post('invoices/{id}/state', [FinanceController::class, 'invoiceState']);
    Route::get('invoices/{id}/pdf', [FinanceController::class, 'invoicePdf'])->name('invoices.pdf');
    Route::post('invoices/{id}/payments', [FinanceController::class, 'payment']);
    Route::post('invoices/{id}/credits', [FinanceController::class, 'credit']);
    Route::post('invoices/{id}/checkout', [FinanceController::class, 'checkout']);
    Route::get('payments/{id}/receipt', [FinanceController::class, 'receipt']);
    Route::get('customer-deposits', [FinanceController::class, 'deposits']);
    Route::post('customer-deposits', [FinanceController::class, 'createDeposit']);
    Route::post('customer-deposits/{id}/allocate', [FinanceController::class, 'allocateDeposit']);
    Route::post('customer-deposits/{id}/refund', [FinanceController::class, 'refundDeposit']);
    Route::post('invoices/{id}/transfer-credit', [FinanceController::class, 'transferCredit']);
    Route::post('payments/{id}/refund', [FinanceController::class, 'refund']);
    Route::post('payment-attempts/{id}/reconcile', [FinanceController::class, 'reconcile']);
    Route::get('payment-providers', [FinanceController::class, 'providers']);
    Route::get('invoice-design', [FinanceController::class, 'design']);
    Route::put('invoice-design', [FinanceController::class, 'saveDesign']);
    Route::get('payroll/runs', [WorkforceController::class, 'runs']);
    Route::post('payroll/runs', [WorkforceController::class, 'createRun']);
    Route::get('payroll/runs/{id}', [WorkforceController::class, 'run']);
    Route::put('payroll/runs/{id}', [WorkforceController::class, 'reviseRun']);
    Route::post('payroll/runs/{id}/review', [WorkforceController::class, 'review']);
    Route::post('payroll/runs/{id}/approve', [WorkforceController::class, 'approve']);
    Route::post('payroll/runs/{id}/publish', [WorkforceController::class, 'publish']);
    Route::get('payroll/runs/{id}/pdf', [WorkforceController::class, 'pdf'])->name('payroll.pdf');
    Route::get('payroll/compensation', [WorkforceController::class, 'compensation']);
    Route::post('payroll/compensation', [WorkforceController::class, 'createCompensation']);
    Route::put('payroll/compensation/{id}/end', [WorkforceController::class, 'endCompensation']);
    Route::get('payroll/timesheets', [WorkforceController::class, 'timesheets']);
    Route::post('payroll/timesheets', [WorkforceController::class, 'createTimesheet']);
    Route::post('payroll/timesheets/{id}/approve', [WorkforceController::class, 'approveTimesheet']);
    Route::get('payroll/export', [WorkforceController::class, 'export']);
    Route::post('payroll/import', [WorkforceController::class, 'import']);
});
