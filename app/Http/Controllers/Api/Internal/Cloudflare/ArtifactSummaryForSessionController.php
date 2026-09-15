<?php

namespace App\Http\Controllers\Api\Internal\Cloudflare;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/*
 * Placeholder until its package lands; the route exists so the surface is
 * declared once, and it behaves as absent until then.
 */
class ArtifactSummaryForSessionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        abort(404);
    }
}
