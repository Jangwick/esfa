<?php

use App\Http\Controllers\AiRulesController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\FoundationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntegrationClientController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpenApiController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SimulatorController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookDeliveryController;
use App\Http\Controllers\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/openapi.yaml', OpenApiController::class);
Route::get('/cron/run', [CronController::class, 'run']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/login/otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:login');
Route::post('/login/otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'staff', 'audit.log', 'cache.flush', 'run.scheduler'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'profile']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::get('/foundation', FoundationController::class)->middleware('cache.api:30');
    Route::get('/users', [UserController::class, 'index'])->middleware('cache.api:30');
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{user}/unlock', [UserController::class, 'unlock']);
    Route::get('/roles', [UserController::class, 'roles'])->middleware('cache.api:30');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('cache.api:30');

    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/mark-all-as-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::get('/automation', [AutomationController::class, 'index']);
    Route::post('/automation/run', [AutomationController::class, 'run'])->middleware('throttle:6,1,automation-run');

    Route::get('/integration-clients', [IntegrationClientController::class, 'index'])->middleware('cache.api:30');
    Route::post('/integration-clients', [IntegrationClientController::class, 'store']);
    Route::put('/integration-clients/{client}', [IntegrationClientController::class, 'update']);
    Route::delete('/integration-clients/{client}', [IntegrationClientController::class, 'destroy']);
    Route::post('/integration-clients/{client}/tokens', [IntegrationClientController::class, 'issueToken']);
    Route::delete('/integration-clients/{client}/tokens/{token}', [IntegrationClientController::class, 'revokeToken']);
    Route::get('/webhook-endpoints', [WebhookEndpointController::class, 'index'])->middleware('cache.api:30');
    Route::post('/webhook-endpoints', [WebhookEndpointController::class, 'store']);
    Route::put('/webhook-endpoints/{endpoint}', [WebhookEndpointController::class, 'update']);
    Route::delete('/webhook-endpoints/{endpoint}', [WebhookEndpointController::class, 'destroy']);
    Route::post('/webhook-endpoints/{endpoint}/test', [WebhookEndpointController::class, 'test']);
    Route::get('/webhook-deliveries', [WebhookDeliveryController::class, 'index'])->middleware('cache.api:30');
    Route::post('/webhook-deliveries/resend-failed', [WebhookDeliveryController::class, 'resendFailed']);
    Route::post('/webhook-deliveries/{delivery}/resend', [WebhookDeliveryController::class, 'resend']);
    require __DIR__.'/support.php';
    require __DIR__.'/payments.php';
    require __DIR__.'/logistics.php';
    require __DIR__.'/billing.php';
    require __DIR__.'/simulator.php';
    Route::get('/dashboard', [\App\Http\Controllers\AnalyticsController::class, 'dashboard'])->middleware('cache.api:30');
    Route::get('/analytics/overview', [\App\Http\Controllers\AnalyticsController::class, 'overview'])->middleware('cache.api:30');
    Route::get('/analytics/charts', [\App\Http\Controllers\AnalyticsController::class, 'charts'])->middleware('cache.api:30');
    Route::get('/analytics/export', [\App\Http\Controllers\AnalyticsController::class, 'export']);
    Route::post('/analytics/ask', [\App\Http\Controllers\AnalyticsController::class, 'ask'])->middleware('throttle:10,1,ai-ask');
    Route::get('/ai/rules', AiRulesController::class);
});

require __DIR__.'/integrations.php';
// Built-in stand-in for the partner webhook receiver on hosted demos: SFA_PARTNER_URL can point at
// /api/v1/simrx so the simulator connects to itself. Signature-checked; 404s when the simulator is off.
Route::prefix('simrx')->middleware('throttle:30,1')->group(function (): void {
    Route::get('/', [SimulatorController::class, 'simrxPage']);
    Route::post('/api/setup', [SimulatorController::class, 'simrxSetup']);
    Route::post('/webhooks', [SimulatorController::class, 'simrxWebhook']);
});
require __DIR__.'/payments-webhooks.php';
require __DIR__.'/logistics-webhooks.php';
