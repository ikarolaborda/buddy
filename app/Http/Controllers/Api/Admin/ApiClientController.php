<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware('throttle:10,1')]
class ApiClientController extends Controller
{
    public function __construct(
        protected ApiKeyService $apiKeys,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string'],
            'expires_days' => ['sometimes', 'integer', 'min:1', 'max:730'],
        ]);

        $scopes = [];

        foreach ($validated['scopes'] ?? ['tasks:read', 'tasks:write'] as $scope) {
            $parsed = ApiScope::tryFrom($scope);

            if ($parsed === null) {
                return response()->json(['error' => "Unknown scope: {$scope}"], 422);
            }

            // Admin keys are minted only from the CLI on purpose: a leaked
            // admin key must not be able to breed more admin keys.
            if ($parsed === ApiScope::Admin) {
                return response()->json([
                    'error' => 'Admin-scoped keys can only be issued via buddy:client:create.',
                ], 422);
            }

            $scopes[] = $parsed;
        }

        $client = ApiClient::firstOrCreate([
            'name' => $validated['name'],
            'project' => 'buddy',
        ]);

        $issued = $this->apiKeys->issue(
            $client,
            $scopes,
            isset($validated['expires_days']) ? now()->addDays($validated['expires_days']) : null,
        );

        return response()->json([
            'client_id' => $client->id,
            'client_name' => $client->name,
            'scopes' => array_map(fn (ApiScope $scope) => $scope->value, $scopes),
            'api_key' => $issued['plaintext'],
            'note' => 'This key is shown once. Store it now.',
        ], 201);
    }
}
