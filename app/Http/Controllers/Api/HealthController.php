<?php

namespace App\Http\Controllers\Api;

use App\Contracts\MemoryGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(MemoryGateway $memory): JsonResponse
    {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = true;
        } catch (\Throwable) {
            $checks['database'] = false;
        }

        try {
            Queue::connection()->size();
            $checks['queue'] = true;
        } catch (\Throwable) {
            $checks['queue'] = false;
        }

        if (config('buddy.health.check_memory')) {
            $checks['memory'] = $memory->health()->healthy;
        }

        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'degraded',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
