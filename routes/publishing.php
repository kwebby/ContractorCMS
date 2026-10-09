<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use App\Http\Controllers\PublishingController;
use Illuminate\Support\Facades\Route;

Route::get('/p/{slug}', [PublishingController::class, 'publicPage'])->where('slug', '[a-z0-9-]+')->name('public.page');
Route::get('/sitemap.xml', [PublishingController::class, 'sitemap'])->name('public.sitemap');
Route::get('/robots.txt', [PublishingController::class, 'robots'])->name('public.robots');
Route::get('/assets/public-site.{extension}', [PublishingController::class, 'publicAsset'])->where('extension', 'css|js')->name('public.asset');
Route::get('/theme-assets/{id}/{path}', [PublishingController::class, 'themeAsset'])->where('path', '.+')->name('public.theme-asset');
Route::middleware('auth')->prefix('api/v1')->group(function (): void {
    Route::get('/pages', [PublishingController::class, 'index']);
    Route::post('/pages', [PublishingController::class, 'store']);
    Route::get('/pages/{id}', [PublishingController::class, 'show']);
    Route::put('/pages/{id}', [PublishingController::class, 'update']);
    Route::delete('/pages/{id}', [PublishingController::class, 'archive']);
    Route::post('/pages/{id}/publish', [PublishingController::class, 'publish']);
    Route::post('/pages/{id}/unpublish', [PublishingController::class, 'unpublish']);
    Route::get('/pages/{id}/revisions', [PublishingController::class, 'revisions']);
    Route::post('/pages/{id}/revisions/{revisionId}/restore', [PublishingController::class, 'restoreRevision']);
    Route::get('/pages/{id}/preview', [PublishingController::class, 'preview']);
    Route::get('/seo/diagnostics', [PublishingController::class, 'diagnostics']);
    Route::get('/seo/settings', [PublishingController::class, 'seoSettings']);
    Route::put('/seo/settings', [PublishingController::class, 'updateSeoSettings']);
    Route::get('/themes', [PublishingController::class, 'themeIndex']);
    Route::get('/themes/starter', [PublishingController::class, 'themeStarter']);
    Route::post('/themes', [PublishingController::class, 'themeUpload']);
    Route::post('/themes/rollback', [PublishingController::class, 'themeRollback']);
    Route::post('/themes/default', [PublishingController::class, 'themeDefault']);
    Route::post('/themes/{id}/activate', [PublishingController::class, 'themeActivate']);
    Route::get('/themes/{id}/preview', [PublishingController::class, 'themePreview']);
});
