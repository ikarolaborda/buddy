<?php

namespace App\Http\Controllers\Api\Buddy\Diagnostics;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/*
 * Placeholder until P7 browser diagnostics lands; the route exists so the surface is
 * declared once, and it behaves as absent until then.
 */
class ShowDiagnosticCaptureController extends Controller
{
    public function __invoke(): JsonResponse
    {
        abort(404);
    }
}
