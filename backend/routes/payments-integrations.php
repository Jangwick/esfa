<?php
use App\Http\Controllers\MirrorController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RefundController;
use Illuminate\Support\Facades\Route;
Route::put('/customers/{external_id}',[MirrorController::class,'customer'])->middleware('abilities:customers:write');
Route::put('/sellers/{external_id}',[MirrorController::class,'seller'])->middleware('abilities:sellers:write');
Route::put('/categories/{external_id}',[MirrorController::class,'category'])->middleware('abilities:catalog:write');
Route::put('/orders/{external_id}',[MirrorController::class,'order'])->middleware('abilities:orders:write');
Route::post('/orders/{external_id}/cancel',[MirrorController::class,'cancel'])->middleware('abilities:orders:write');
Route::post('/payments',[PaymentController::class,'createIntegration'])->middleware(['abilities:payments:write','idempotency']);
Route::get('/payments/{reference}',[PaymentController::class,'showIntegration'])->middleware('abilities:payments:read');
Route::post('/refunds',[RefundController::class,'createIntegration'])->middleware(['abilities:refunds:write','idempotency']);
Route::get('/refunds/{reference}',[RefundController::class,'showIntegration'])->middleware('abilities:payments:read');
