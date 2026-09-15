<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 * Guards /api/internal/cloudflare. The Worker presents its own limited
 * service key; it never holds a client API key. The key only opens the door:
 * every route behind it re-derives client and task authority from a
 * delegation or a view session, so a leaked service key alone cannot read
 * or mutate any tenant's task.
 */
class AuthenticateEdgeService
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('buddy.edge.service_key');

        if ($configured === '') {
            return response()->json(['error' => 'edge_disabled'], 404);
        }

        $presented = (string) $request->header('X-Buddy-Edge-Key', '');

        if ($presented === '' || ! hash_equals($configured, $presented)) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return $next($request);
    }
}
