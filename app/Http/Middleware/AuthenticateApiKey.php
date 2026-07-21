<?php

namespace App\Http\Middleware;

use App\Enums\ApiScope;
use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(
        protected ApiKeyService $apiKeys,
    ) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        if (! config('buddy.api.auth_required')) {
            return $next($request);
        }

        $key = $this->apiKeys->verify($request->bearerToken());

        if ($key === null) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        if ($scope !== null) {
            $required = ApiScope::from($scope);

            if (! $key->hasScope($required)) {
                return response()->json(['error' => 'Insufficient scope.'], 403);
            }
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_client', $key->client);

        return $next($request);
    }
}
