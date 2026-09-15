<?php

use App\Http\Controllers\Api\Admin\ApiClientController;
use App\Http\Controllers\Api\Buddy\Artifacts\ArtifactSummaryController;
use App\Http\Controllers\Api\Buddy\Artifacts\CompleteArtifactUploadController;
use App\Http\Controllers\Api\Buddy\Artifacts\DeleteArtifactController;
use App\Http\Controllers\Api\Buddy\Artifacts\DownloadArtifactController;
use App\Http\Controllers\Api\Buddy\Artifacts\ReserveArtifactUploadController;
use App\Http\Controllers\Api\Buddy\BuddyTaskController;
use App\Http\Controllers\Api\Buddy\CapabilitiesController;
use App\Http\Controllers\Api\Buddy\Diagnostics\CreateDiagnosticCaptureController;
use App\Http\Controllers\Api\Buddy\Diagnostics\ShowDiagnosticCaptureController;
use App\Http\Controllers\Api\Buddy\ViewTicketController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Internal\Cloudflare\ArtifactSummaryForSessionController;
use App\Http\Controllers\Api\Internal\Cloudflare\CompleteDiagnosticCaptureController;
use App\Http\Controllers\Api\Internal\Cloudflare\DelegatedInterventionController;
use App\Http\Controllers\Api\Internal\Cloudflare\DelegatedTaskStatusController;
use App\Http\Controllers\Api\Internal\Cloudflare\DelegationController;
use App\Http\Controllers\Api\Internal\Cloudflare\SessionExchangeController;
use App\Http\Controllers\Api\Internal\Cloudflare\TaskSnapshotController;
use App\Http\Controllers\Api\Internal\ScalingMetricsController;
use App\Http\Controllers\Api\McpController;
use Illuminate\Support\Facades\Route;

Route::get('health', [HealthController::class, 'health'])->name('health');
Route::get('ready', [HealthController::class, 'ready'])->name('ready');

// Worker autoscaling signal (P0, ADR 0012). Key-authenticated inside the
// controller; 404 until BUDDY_SCALING_METRICS_KEY is configured.
Route::get('internal/scaling/queue-depth', ScalingMetricsController::class)
    ->name('internal.scaling.queue_depth');

// Narrow service callbacks for the Cloudflare edge (plan §5). The service key
// opens the prefix; every route re-derives client and task authority from a
// delegation or a view session and 404s while its feature flag is off.
Route::prefix('internal/cloudflare')->middleware('edge.service')->group(function () {
    Route::post('sessions/exchange', SessionExchangeController::class)
        ->name('internal.cloudflare.sessions.exchange');
    Route::get('tasks/{task}/snapshot', TaskSnapshotController::class)
        ->name('internal.cloudflare.tasks.snapshot');
    Route::post('tasks/{task}/interventions', DelegatedInterventionController::class)
        ->name('internal.cloudflare.tasks.interventions');
    Route::post('tasks/{task}/delegations', DelegationController::class)
        ->name('internal.cloudflare.tasks.delegations');
    Route::get('tasks/{task}/status', DelegatedTaskStatusController::class)
        ->name('internal.cloudflare.tasks.status');
    Route::get('tasks/{task}/artifacts/{artifact}/summary', ArtifactSummaryForSessionController::class)
        ->name('internal.cloudflare.tasks.artifacts.summary');
    Route::post('tasks/{task}/diagnostic-captures/{capture}/complete', CompleteDiagnosticCaptureController::class)
        ->name('internal.cloudflare.tasks.captures.complete');
});

Route::get('buddy/capabilities', CapabilitiesController::class)
    ->name('buddy.capabilities');

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
    Route::post('tasks/{task}/interventions', [BuddyTaskController::class, 'intervene'])
        ->middleware('auth.buddy:interventions:execute')
        ->name('buddy.tasks.interventions');
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
    Route::post('tasks/{task}/view-tickets', ViewTicketController::class)
        ->middleware('auth.buddy:tasks:read')
        ->name('buddy.tasks.view_tickets');

    // P5 private artifacts in R2 (plan §9); every route 404s while
    // BUDDY_EDGE_ARTIFACTS is false and re-checks task ownership.
    Route::post('tasks/{task}/artifact-uploads', ReserveArtifactUploadController::class)
        ->middleware('auth.buddy:tasks:write')
        ->name('buddy.tasks.artifact_uploads.store');
    Route::post('tasks/{task}/artifact-uploads/{upload}/complete', CompleteArtifactUploadController::class)
        ->middleware('auth.buddy:tasks:write')
        ->name('buddy.tasks.artifact_uploads.complete');
    Route::get('tasks/{task}/artifacts/{artifact}/download', DownloadArtifactController::class)
        ->middleware('auth.buddy:tasks:read')
        ->name('buddy.tasks.artifacts.download');
    Route::get('tasks/{task}/artifacts/{artifact}/summary', ArtifactSummaryController::class)
        ->middleware('auth.buddy:tasks:read')
        ->name('buddy.tasks.artifacts.summary');
    Route::delete('tasks/{task}/artifacts/{artifact}', DeleteArtifactController::class)
        ->middleware('auth.buddy:tasks:write')
        ->name('buddy.tasks.artifacts.destroy');

    // P7 authorized browser diagnostics (plan §11); requires the new
    // diagnostics:capture scope and stays inert while the live flag is off.
    Route::post('tasks/{task}/diagnostic-captures', CreateDiagnosticCaptureController::class)
        ->middleware('auth.buddy:diagnostics:capture')
        ->name('buddy.tasks.diagnostic_captures.store');
    Route::get('tasks/{task}/diagnostic-captures/{capture}', ShowDiagnosticCaptureController::class)
        ->middleware('auth.buddy:tasks:read')
        ->name('buddy.tasks.diagnostic_captures.show');
});
