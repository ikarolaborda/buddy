<?php

use App\Http\Controllers\Api\Buddy\BuddyTaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('buddy')->group(function () {
    Route::post('tasks', [BuddyTaskController::class, 'store']);
    Route::get('tasks/{task}', [BuddyTaskController::class, 'show']);
    Route::post('tasks/{task}/artifacts', [BuddyTaskController::class, 'attachArtifact']);
    Route::post('tasks/{task}/evaluate', [BuddyTaskController::class, 'evaluate']);
    Route::post('tasks/{task}/close', [BuddyTaskController::class, 'close']);
});
