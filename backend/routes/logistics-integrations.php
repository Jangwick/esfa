<?php
use App\Http\Controllers\LogisticsIntegrationController;
use Illuminate\Support\Facades\Route;
Route::post('/quotes', [LogisticsIntegrationController::class, 'quotes'])->middleware('abilities:quotes:read');
Route::post('/shipments', [LogisticsIntegrationController::class, 'store'])->middleware(['abilities:shipments:write', 'idempotency']);
Route::get('/shipments/{reference}', [LogisticsIntegrationController::class, 'show'])->middleware('abilities:shipments:read');
