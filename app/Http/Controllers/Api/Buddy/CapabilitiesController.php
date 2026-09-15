<?php

namespace App\Http\Controllers\Api\Buddy;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/*
 * Public, non-sensitive capability metadata (plan §10): schema versions,
 * enabled edge features and bounded limits. It is safe to cache at the edge
 * because it names no client, task or secret, and it changes only with a
 * deployment, so the deployment version is the cache key.
 */
class CapabilitiesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $edge = config('buddy.edge');

        return response()
            ->json([
                'schema_version' => (int) ($edge['schema_version'] ?? 1),
                'deployment' => (string) config('app.deployment_version', 'unversioned'),
                'features' => [
                    'events' => (bool) $edge['events'],
                    'progress' => (bool) $edge['progress'],
                    'supervision' => (bool) $edge['supervision'],
                    'auto_recovery' => (bool) $edge['auto_recovery'],
                    'artifacts' => (bool) $edge['artifacts'],
                    'read_cache' => (bool) $edge['read_cache'],
                    'browser_diagnostics' => (bool) $edge['browser_diagnostics'],
                ],
                'limits' => [
                    'event_max_bytes' => (int) $edge['event_max_bytes'],
                    'upload_bytes' => (int) $edge['quotas']['upload_bytes'],
                    'task_bytes' => (int) $edge['quotas']['task_bytes'],
                    'client_daily_bytes' => (int) $edge['quotas']['client_daily_bytes'],
                    'captures_per_client_per_day' => (int) $edge['quotas']['captures_per_client_per_day'],
                    'capture_seconds' => (int) $edge['quotas']['capture_seconds'],
                    'council_artifact_chars' => (int) config('buddy_agents.council.artifact_chars'),
                    'council_packet_chars' => (int) config('buddy_agents.council.packet_chars'),
                ],
                'retention' => [
                    'artifact_days' => (int) $edge['retention']['artifact_days'],
                    'quarantine_days' => (int) $edge['retention']['quarantine_days'],
                    'event_days' => (int) $edge['retention']['event_days'],
                ],
            ])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
