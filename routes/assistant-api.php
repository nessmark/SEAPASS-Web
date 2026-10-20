<?php
use App\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;
Route::middleware(['auth:sanctum,api', 'throttle:30,1'])->prefix('assistant')->group(function () {
    Route::get('/status', [AssistantController::class, 'status']);
    Route::post('/chat', [AssistantController::class, 'chat']);
    Route::post('/tool', [AssistantController::class, 'tool']);
    Route::post('/session', [AssistantController::class, 'session']);
});
