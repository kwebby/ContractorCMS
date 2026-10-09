<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use App\Http\Controllers\OperationsWorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('api/v1')->group(function () {
    Route::get('properties', [OperationsWorkflowController::class, 'properties'])->name('properties.index');
    Route::post('properties', [OperationsWorkflowController::class, 'saveProperty']);
    Route::get('properties/{id}', [OperationsWorkflowController::class, 'property']);
    Route::put('properties/{id}', [OperationsWorkflowController::class, 'saveProperty']);
    Route::delete('properties/{id}', [OperationsWorkflowController::class, 'archiveProperty']);
    Route::get('visits', [OperationsWorkflowController::class, 'visits']);
    Route::post('visits', [OperationsWorkflowController::class, 'createVisit']);
    Route::get('visits/{id}', [OperationsWorkflowController::class, 'visit']);
    Route::post('visits/{id}/confirm', [OperationsWorkflowController::class, 'confirmVisit']);
    Route::post('visits/{id}/cancel', [OperationsWorkflowController::class, 'cancelVisit']);
    Route::post('visits/{id}/complete', [OperationsWorkflowController::class, 'completeVisit']);
    Route::get('change-orders', [OperationsWorkflowController::class, 'changes']);
    Route::post('change-orders', [OperationsWorkflowController::class, 'createChange']);
    Route::get('change-orders/{id}', [OperationsWorkflowController::class, 'change']);
    Route::put('change-orders/{id}', [OperationsWorkflowController::class, 'reviseChange']);
    Route::post('change-orders/{id}/send', [OperationsWorkflowController::class, 'sendChange']);
    Route::post('change-orders/{id}/accept', [OperationsWorkflowController::class, 'acceptChange']);
    Route::post('change-orders/{id}/reject', [OperationsWorkflowController::class, 'rejectChange']);
    Route::post('change-orders/{id}/cancel', [OperationsWorkflowController::class, 'cancelChange']);
    Route::get('branches', [OperationsWorkflowController::class, 'branches']);
    Route::post('branches', [OperationsWorkflowController::class, 'saveBranch']);
    Route::put('branches/{id}', [OperationsWorkflowController::class, 'saveBranch']);
});
