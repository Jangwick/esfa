<?php

use App\Http\Controllers\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

Route::prefix('integrations')->middleware([
    'auth:sanctum', 'integration.only', 'throttle:integrations', 'audit.log', 'cache.flush', 'run.scheduler',
])->group(function () {
    Route::middleware('abilities:webhooks:manage')->group(function () {
        Route::get('webhook-endpoints', [WebhookEndpointController::class, 'index']);
        Route::post('webhook-endpoints', [WebhookEndpointController::class, 'store']);
        Route::put('webhook-endpoints/{endpoint}', [WebhookEndpointController::class, 'update']);
        Route::delete('webhook-endpoints/{endpoint}', [WebhookEndpointController::class, 'destroy']);
    });
    require __DIR__.'/support-integrations.php';
    require __DIR__.'/payments-integrations.php';
    require __DIR__.'/logistics-integrations.php';
    Route::get('subscriptions',[\App\Http\Controllers\BillingIntegrationController::class,'subscriptions'])->middleware('abilities:subscriptions:read');
    Route::get('sellers/{externalId}/statements',[\App\Http\Controllers\BillingIntegrationController::class,'statements'])->middleware('abilities:commissions:read');
    Route::get('analytics/summary',[\App\Http\Controllers\AnalyticsController::class,'integration'])->middleware('abilities:analytics:read');
});
