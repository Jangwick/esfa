<?php
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;
Route::post('/webhooks/payments/{gateway}',[PaymentController::class,'webhook'])->middleware('throttle:inbound-webhooks');
