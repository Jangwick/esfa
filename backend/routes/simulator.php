<?php

use App\Http\Controllers\SimulatorController;
use Illuminate\Support\Facades\Route;

// Integration Simulator (Admin only): connects the simulated outside systems, lists pickers, and keeps a few stand-ins (the
// customer's ticket answers on the staff ticket page, the scheduler clock and the gateway settlement file).
Route::prefix('simulator')->group(function (): void {
    Route::get('/', [SimulatorController::class, 'index']);
    Route::get('/settlement-file', [SimulatorController::class, 'settlementFile']);
    Route::post('/connect', [SimulatorController::class, 'connect']);
    Route::post('/tickets/{ticket}/reply', [SimulatorController::class, 'ticketReply']);
    Route::post('/tickets/{ticket}/rating', [SimulatorController::class, 'ticketRating']);
    Route::post('/run-scheduled', [SimulatorController::class, 'runScheduled'])->middleware('throttle:6,1,simulator-run');
});
