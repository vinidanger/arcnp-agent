<?php

use App\Http\Controllers\Api\BackupBundleDownloadController;
use App\Http\Controllers\Api\BackupDownloadController;
use App\Http\Controllers\Api\CommandController;
use App\Http\Controllers\Api\FileDownloadController;
use App\Http\Controllers\Api\FileUploadController;
use Illuminate\Support\Facades\Route;

Route::middleware('agent.signed')->group(function () {
    Route::post('/commands', [CommandController::class, 'store']);
    Route::get('/commands/{uuid}', [CommandController::class, 'show']);
    Route::get('/backups/{username}/bundle/{token}', [BackupBundleDownloadController::class, 'show']);
    Route::get('/backups/{username}/{filename}', [BackupDownloadController::class, 'show']);
    Route::post('/files/{username}/upload', [FileUploadController::class, 'store']);
    Route::get('/files/{username}/download/{token}', [FileDownloadController::class, 'show']);
});
