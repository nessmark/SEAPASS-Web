<?php
use App\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;
Route::middleware(['auth', 'throttle:30,1'])->prefix('assistant')->group(function () {
    Route::get('/status', [AssistantController::class, 'status']);
    Route::post('/chat', [AssistantController::class, 'chat']);
    Route::post('/tool', [AssistantController::class, 'tool']);
    Route::post('/session', [AssistantController::class, 'session']);
});

// Generic empty host. Authentication is required by every data/model endpoint.
Route::view('/assistant/embed', 'assistant-embed');
