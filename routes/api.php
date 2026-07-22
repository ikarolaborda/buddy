<?php

use App\Http\Controllers\Api\Admin\ApiClientController;
use App\Http\Controllers\Api\Buddy\BuddyTaskController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\McpController;
use Illuminate\Support\Facades\Route;

Route::get('health', [HealthController::class, 'health'])->name('health');
Route::get('ready', [HealthController::class, 'ready'])->name('ready');

Route::post('mcp', [McpController::class, 'post'])
    ->middleware(['mcp.origin', 'auth.buddy'])
    ->name('mcp.post');
Route::get('mcp', [McpController::class, 'get'])
    ->middleware('mcp.origin')
    ->name('mcp.get');

Route::post('admin/clients', [ApiClientController::class, 'store'])
    ->middleware('auth.buddy:admin')
    ->name('admin.clients.store');

Route::prefix('buddy')->group(function () {
    Route::post('tasks', [BuddyTaskController::class, 'store'])
        ->middleware('auth.buddy:tasks:write')
        ->name('buddy.tasks.store');
    Route::get('tasks/{task}', [BuddyTaskController::class, 'show'])
        ->middleware('auth.buddy:tasks:read')
        ->name('buddy.tasks.show');
    Route::post('tasks/{task}/artifacts', [BuddyTaskController::class, 'attachArtifact'])
        ->middleware('auth.buddy:tasks:write')
        ->name('buddy.tasks.artifacts');
    Route::post('tasks/{task}/evaluate', [BuddyTaskController::class, 'evaluate'])
        ->middleware('auth.buddy:tasks:write')
        ->name('buddy.tasks.evaluate');
    Route::post('tasks/{task}/refine', [BuddyTaskController::class, 'refine'])
        ->middleware('auth.buddy:tasks:write')
        ->name('buddy.tasks.refine');
    Route::post('tasks/{task}/council', [BuddyTaskController::class, 'council'])
        ->middleware('auth.buddy:tasks:write')
        ->name('buddy.tasks.council');
    Route::post('tasks/{task}/close', [BuddyTaskController::class, 'close'])
        ->middleware('auth.buddy:tasks:write')
        ->name('buddy.tasks.close');
});
