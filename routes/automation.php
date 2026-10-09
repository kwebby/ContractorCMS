<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use App\Http\Controllers\AiToolsController;
use Illuminate\Support\Facades\Route;

Route::get('/tools/reports/verify/{token}', [AiToolsController::class, 'confirmPage'])->middleware('throttle:public')->name('tools.report.confirm');
Route::post('/tools/reports/verify/{token}', [AiToolsController::class, 'verifyReport'])->middleware('throttle:public');
Route::get('/tools/{slug}', [AiToolsController::class, 'page'])->name('tools.page');
Route::post('/api/v1/tools/{slug}/generate', [AiToolsController::class, 'generate'])->middleware('throttle:5,1');
Route::post('/api/v1/tool-reports/{id}/email', [AiToolsController::class, 'requestReport'])->middleware('throttle:3,1');
