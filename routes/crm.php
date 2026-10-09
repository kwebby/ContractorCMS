<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use App\Http\Controllers\CrmNotesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('api/v1')->group(function (): void {
    Route::get('crm-notes', [CrmNotesController::class, 'index'])->name('crm-notes.index');
    Route::post('crm-notes', [CrmNotesController::class, 'store']);
    Route::get('crm-notes/{id}', [CrmNotesController::class, 'show']);
    Route::patch('crm-notes/{id}', [CrmNotesController::class, 'update']);
});
