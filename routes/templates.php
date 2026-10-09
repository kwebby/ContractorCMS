<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use App\Http\Controllers\EmailTemplatesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('api/v1/email-templates')->group(function (): void {
    Route::get('/', [EmailTemplatesController::class, 'index'])->name('email-templates.index');
    Route::put('/{key}', [EmailTemplatesController::class, 'update']);
    Route::get('/{key}/revisions', [EmailTemplatesController::class, 'revisions']);
    Route::post('/{key}/preview', [EmailTemplatesController::class, 'preview']);
});
