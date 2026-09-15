<?php

namespace App\Http\Controllers\Api\Buddy\Artifacts;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/*
 * Placeholder until P5 artifact storage lands; the route exists so the surface is
 * declared once, and it behaves as absent until then.
 */
class ArtifactSummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        abort(404);
    }
}
