<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientAccessController;
use App\Http\Controllers\CommunicationsController;
use App\Http\Controllers\FilesController;
use App\Http\Controllers\GuestChatController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PublishingController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ThemeSettingsController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'loginPage'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/register', [AuthController::class, 'registerPage'])->middleware('guest');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:public');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/verify-email/{token}', [AuthController::class, 'verify'])->middleware('throttle:public');
Route::get('/forgot-password', [AuthController::class, 'forgotPage']);
Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:login');
Route::get('/reset-password/{token}', [AuthController::class, 'resetPage']);
Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:login');
Route::get('/invitations/{token}', [AuthController::class, 'invitationPage']);
Route::post('/invitations/accept', [AuthController::class, 'acceptInvitation'])->middleware('throttle:login');
Route::get('/mfa', [AuthController::class, 'mfaPage']);
Route::post('/mfa', [AuthController::class, 'mfa'])->middleware('throttle:login');
Route::get('/files/{id}', [FilesController::class, 'download']);
Route::post('/api/v1/public/leads', [OperationsController::class, 'publicLead'])->middleware('throttle:public');
Route::middleware('auth')->group(function (): void {
    Route::get('/security', [AuthController::class, 'security']);
    Route::post('/api/v1/security/mfa/setup', [AuthController::class, 'setupMfa'])->middleware('throttle:login');
    Route::post('/api/v1/security/mfa/confirm', [AuthController::class, 'confirmMfa'])->middleware('throttle:login');
    Route::post('/api/v1/security/confirm-password', [SettingsController::class, 'confirmPassword'])->middleware('throttle:login');
    Route::get('/app/settings/{settingsPage}', [WorkspaceController::class, 'settingsPage'])->name('workspace.settings');
    Route::get('/app/themes/upload', [WorkspaceController::class, 'themeUpload'])->name('workspace.themes.upload');
    Route::get('/app/themes/{id}/settings', [WorkspaceController::class, 'themeSettings'])->name('workspace.themes.settings');
    Route::get('/app/website/seo', [WorkspaceController::class, 'websiteSeo'])->name('workspace.website.seo');
    Route::get('/app/website/local-seo/locations/{locationId}', [WorkspaceController::class, 'websitePage'])->defaults('websitePage', 'location')->name('workspace.website.location');
    Route::get('/app/website/local-seo/{websitePage}', [WorkspaceController::class, 'websitePage'])->whereIn('websitePage', ['business-profile', 'locations', 'citations', 'page-plan'])->name('workspace.website.local-seo');
    Route::get('/app/website/{websitePage}', [WorkspaceController::class, 'websitePage'])->whereIn('websitePage', ['homepage', 'appearance', 'navigation', 'local-seo'])->name('workspace.website');
    Route::get('/app/{section?}', [WorkspaceController::class, 'page'])->name('workspace');
    Route::get('/api/v1/dashboard', [WorkspaceController::class, 'dashboard']);
    Route::get('/api/v1/reports', [WorkspaceController::class, 'reports']);
    Route::get('/api/v1/conversations', [CommunicationsController::class, 'conversations']);
    Route::post('/api/v1/conversations', [CommunicationsController::class, 'create']);
    Route::get('/api/v1/conversations/{id}/messages', [CommunicationsController::class, 'messages']);
    Route::post('/api/v1/conversations/{id}/messages', [CommunicationsController::class, 'send'])->middleware('throttle:60,1');
    Route::get('/api/v1/notifications', [CommunicationsController::class, 'notifications']);
    Route::patch('/api/v1/notifications/{id}', [CommunicationsController::class, 'readNotification']);
    Route::get('/api/v1/team', [CommunicationsController::class, 'team']);
    Route::post('/api/v1/invitations', [CommunicationsController::class, 'invite']);
    Route::patch('/api/v1/team/{id}', [CommunicationsController::class, 'updateMember']);
    Route::get('/api/v1/settings/{group}', [SettingsController::class, 'get']);
    Route::put('/api/v1/settings/{group}', [SettingsController::class, 'put']);
    Route::post('/api/v1/mail/test', [SettingsController::class, 'testMail']);
    Route::get('/api/v1/health', [SettingsController::class, 'health']);
    Route::post('/api/v1/files', [FilesController::class, 'upload'])->middleware('throttle:30,1');
    Route::get('/api/v1/{resource}', [OperationsController::class, 'index'])->whereIn('resource', ['leads', 'customers', 'jobs', 'tasks', 'assets', 'agreements', 'projects', 'purchases']);
    Route::post('/api/v1/{resource}', [OperationsController::class, 'store'])->whereIn('resource', ['leads', 'customers', 'jobs', 'tasks', 'assets', 'agreements', 'projects', 'purchases']);
    Route::get('/api/v1/{resource}/{id}', [OperationsController::class, 'show'])->whereIn('resource', ['leads', 'customers', 'jobs', 'tasks', 'assets', 'agreements', 'projects', 'purchases']);
    Route::patch('/api/v1/{resource}/{id}', [OperationsController::class, 'update'])->whereIn('resource', ['leads', 'customers', 'jobs', 'tasks', 'assets', 'agreements', 'projects', 'purchases']);
});
if (file_exists(__DIR__.'/finance.php')) {
    require __DIR__.'/finance.php';
}
if (file_exists(__DIR__.'/publishing.php')) {
    require __DIR__.'/publishing.php';
}
Route::get('/', [PublishingController::class, 'home']);

if (file_exists(__DIR__.'/automation.php')) {
    require __DIR__.'/automation.php';
}
Route::post('/api/v1/public/chat', [GuestChatController::class, 'start'])->middleware('throttle:public');
Route::get('/api/v1/public/chat/{id}/messages', [GuestChatController::class, 'messages'])->middleware('throttle:60,1');
Route::post('/api/v1/public/chat/{id}/messages', [GuestChatController::class, 'send'])->middleware('throttle:public');
Route::middleware('auth')->group(function (): void {
    Route::post('/api/v1/customers/{id}/portal-invitation', [ClientAccessController::class, 'invite']);
    Route::get('/api/v1/conversation-participants', [ClientAccessController::class, 'participants']);
});
Route::get('/portal-invitations/{token}', [ClientAccessController::class, 'invitation'])->middleware('throttle:public');
Route::post('/portal-invitations/accept', [ClientAccessController::class, 'accept'])->middleware('throttle:login');
if (file_exists(__DIR__.'/templates.php')) {
    require __DIR__.'/templates.php';
}

if (file_exists(__DIR__.'/operations.php')) {
    require __DIR__.'/operations.php';
}

if (file_exists(__DIR__.'/crm.php')) {
    require __DIR__.'/crm.php';
}

if (file_exists(__DIR__.'/website.php')) {
    require __DIR__.'/website.php';
}

Route::get('/api/v1/website/preview', [PublishingController::class, 'websitePreview'])->middleware('auth');
Route::get('/assets/home-sections.css', [PublishingController::class, 'homepageStyles']);

Route::middleware('auth')->group(function (): void {
    Route::get('/api/v1/themes/{id}/settings', [ThemeSettingsController::class, 'show']);
    Route::put('/api/v1/themes/{id}/settings', [ThemeSettingsController::class, 'update']);
});
