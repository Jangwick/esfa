<?php

use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::post('tickets', [TicketController::class, 'store'])->middleware(['abilities:tickets:write', 'idempotency']);
Route::get('tickets/{reference}', [TicketController::class, 'integrationShow'])->middleware('abilities:tickets:read');
Route::post('tickets/{reference}/messages', [TicketController::class, 'integrationMessage'])->middleware('abilities:tickets:write');
Route::post('tickets/{reference}/csat', [TicketController::class, 'csat'])->middleware('abilities:tickets:write');
Route::get('attachments/{attachment}/download', [TicketController::class, 'download'])->middleware('abilities:tickets:read');
Route::get('kb/articles', [KnowledgeController::class, 'articles'])->middleware('abilities:kb:read');
Route::get('kb/articles/{slug}', [KnowledgeController::class, 'publicArticle'])->middleware('abilities:kb:read');
