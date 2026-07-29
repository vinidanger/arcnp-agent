<?php

use App\Http\Controllers\Api\CommandController;
use Illuminate\Support\Facades\Route;

Route::middleware('agent.signed')->group(function () {
    Route::post('/commands', [CommandController::class, 'store']);
    Route::get('/commands/{uuid}', [CommandController::class, 'show']);
});
