<?php
use App\Http\Controllers\CourierWebhookController;
use Illuminate\Support\Facades\Route;
Route::post('/webhooks/couriers/{courierCode}', CourierWebhookController::class)->middleware('throttle:inbound-webhooks');
