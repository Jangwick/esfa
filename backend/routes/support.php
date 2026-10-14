<?php

use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->group(function () {
    Route::get('options', [TicketController::class, 'options']);
    Route::get('tickets', [TicketController::class, 'index'])->middleware('cache.api:30');
    Route::post('tickets', [TicketController::class, 'store']);
    Route::get('tickets/{ticket}', [TicketController::class, 'show']);
    Route::put('tickets/{ticket}', [TicketController::class, 'update']);
    Route::delete('tickets/{ticket}', [TicketController::class, 'destroy']);
    Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign']);
    Route::post('tickets/{ticket}/status', [TicketController::class, 'status']);
    Route::get('tickets/{ticket}/messages', [TicketController::class, 'messages']);
    Route::post('tickets/{ticket}/messages', [TicketController::class, 'message']);
    Route::post('tickets/{ticket}/ai-draft', [TicketController::class, 'aiDraft'])->middleware('throttle:20,1,ai-draft');
    Route::get('attachments/{attachment}/download', [TicketController::class, 'download']);
    Route::get('kb/categories', [KnowledgeController::class, 'categories']);
    Route::post('kb/categories', [KnowledgeController::class, 'storeCategory']);
    Route::put('kb/categories/{category}', [KnowledgeController::class, 'updateCategory']);
    Route::delete('kb/categories/{category}', [KnowledgeController::class, 'destroyCategory']);
    Route::get('kb/articles', [KnowledgeController::class, 'articles'])->middleware('cache.api:30');
    Route::post('kb/articles', [KnowledgeController::class, 'storeArticle']);
    Route::get('kb/articles/{article}', [KnowledgeController::class, 'showArticle']);
    Route::put('kb/articles/{article}', [KnowledgeController::class, 'updateArticle']);
    Route::delete('kb/articles/{article}', [KnowledgeController::class, 'destroyArticle']);
    Route::get('canned-responses', [KnowledgeController::class, 'canned']);
    Route::post('canned-responses', [KnowledgeController::class, 'storeCanned']);
    Route::put('canned-responses/{response}', [KnowledgeController::class, 'updateCanned']);
    Route::delete('canned-responses/{response}', [KnowledgeController::class, 'destroyCanned']);
    Route::get('sla-policies', [KnowledgeController::class, 'policies']);
    Route::put('sla-policies', [KnowledgeController::class, 'updatePolicies']);
});
