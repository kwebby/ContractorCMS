<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use App\Http\Controllers\WebsiteSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('api/v1/website')->group(function (): void {
    Route::get('/', [WebsiteSettingsController::class, 'show']);
    Route::put('/', [WebsiteSettingsController::class, 'update']);
    Route::post('/publish', [WebsiteSettingsController::class, 'publish']);
    Route::get('/revisions', [WebsiteSettingsController::class, 'revisions']);
    Route::post('/revisions/{id}/restore', [WebsiteSettingsController::class, 'restore']);
    Route::get('/fonts', [WebsiteSettingsController::class, 'fonts']);
    Route::get('/presets', [WebsiteSettingsController::class, 'presets']);
    Route::get('/local-seo', [WebsiteSettingsController::class, 'localSeo']);
    Route::get('/nap-export', [WebsiteSettingsController::class, 'exportNap']);
    Route::get('/citations', [WebsiteSettingsController::class, 'citations']);
    Route::post('/citations', [WebsiteSettingsController::class, 'storeCitation']);
    Route::get('/citations/export', [WebsiteSettingsController::class, 'exportCitations']);
    Route::put('/citations/{id}', [WebsiteSettingsController::class, 'updateCitation']);
});
