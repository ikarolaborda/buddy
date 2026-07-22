<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 * Streamable HTTP MCP servers must validate Origin to block DNS-rebinding
 * and cross-site browser calls (MCP spec 2025-11-25, security best
 * practices). First-party agents send no Origin header and pass through;
 * browser-based clients (e.g. MCP Inspector on http://localhost:6274)
 * must be allowlisted in buddy.api.allowed_origins. ADR 0006.
 */
class ValidateMcpOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null) {
            return $next($request);
        }

        $allowed = config('buddy.api.allowed_origins', []);

        if (! in_array($origin, $allowed, true)) {
            return response()->json(['error' => 'Origin not allowed.'], 403);
        }

        return $next($request);
    }
}
