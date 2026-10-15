<?php
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LogisticsConfigController;
use App\Http\Controllers\LogisticsController;
use Illuminate\Support\Facades\Route;

Route::get('/logistics/options', [LogisticsController::class, 'options']);
Route::post('/logistics/quotes', [LogisticsController::class, 'quotes']);
Route::post('/logistics/address/parse', [LogisticsController::class, 'parseAddress'])->middleware('throttle:20,1,ai-address');
Route::post('/logistics/address/suggest', [LogisticsController::class, 'suggestAddress'])->middleware('throttle:60,1,address-suggest');
Route::post('/logistics/address/reverse', [LogisticsController::class, 'reverseAddress'])->middleware('throttle:60,1,address-reverse');
Route::get('/logistics/shipments', [LogisticsController::class, 'index'])->middleware('cache.api:30');
Route::post('/logistics/shipments', [LogisticsController::class, 'store']);
Route::get('/logistics/shipments/{shipment}', [LogisticsController::class, 'show']);
Route::put('/logistics/shipments/{shipment}', [LogisticsController::class, 'update']);
Route::post('/logistics/shipments/{shipment}/events', [LogisticsController::class, 'event']);
Route::post('/logistics/shipments/{shipment}/proof-of-delivery', [LogisticsController::class, 'proof']);
Route::get('/logistics/shipments/{shipment}/proof-of-delivery/download', [LogisticsController::class, 'proofDownload']);
Route::get('/logistics/awaiting-booking', [LogisticsController::class, 'awaitingBooking'])->middleware('cache.api:30');
Route::get('/logistics/{kind}', [LogisticsConfigController::class, 'index'])->whereIn('kind', ['couriers', 'zones', 'rates', 'warehouses'])->middleware('cache.api:30');
Route::post('/logistics/{kind}', [LogisticsConfigController::class, 'store'])->whereIn('kind', ['couriers', 'zones', 'rates', 'warehouses']);
Route::put('/logistics/{kind}/{record}', [LogisticsConfigController::class, 'update'])->whereIn('kind', ['couriers', 'zones', 'rates', 'warehouses']);
Route::delete('/logistics/{kind}/{record}', [LogisticsConfigController::class, 'destroy'])->whereIn('kind', ['couriers', 'zones', 'rates', 'warehouses']);
foreach (['regions', 'provinces', 'cities', 'barangays'] as $kind) { Route::get('/locations/'.$kind, [LocationController::class, 'index'])->defaults('kind', $kind)->middleware('cache.api:300'); }
Route::post('/locations/import', [LocationController::class, 'import']);
