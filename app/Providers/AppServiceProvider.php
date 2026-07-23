<?php

namespace App\Providers;

use App\Ai\Prompting\PromptRegistry;
use App\Contracts\MemoryGateway;
use App\Enums\MemoryBackend;
use App\Services\EvaluatorOptimizerService;
use App\Services\Memory\HubMemoryGateway;
use App\Services\Memory\LegacyQdrantMemoryGateway;
use App\Services\Memory\ShadowMemoryGateway;
use App\Services\QdrantMemoryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QdrantMemoryService::class);
        $this->app->singleton(EvaluatorOptimizerService::class);
        $this->app->singleton(PromptRegistry::class);

        $this->app->singleton(MemoryGateway::class, function ($app) {
            $backend = MemoryBackend::tryFrom((string) config('buddy.memory.backend'))
                ?? MemoryBackend::Legacy;

            return match ($backend) {
                MemoryBackend::Legacy => $app->make(LegacyQdrantMemoryGateway::class),
                MemoryBackend::Hub => $app->make(HubMemoryGateway::class),
                MemoryBackend::Shadow => $app->make(ShadowMemoryGateway::class),
            };
        });
    }

    public function boot(): void
    {
        // Buckets key on the presented bearer token, not the client IP:
        // several agents share one machine, and the throttle must isolate
        // clients whether it runs before or after auth resolves them.
        $bucket = fn (Request $request): string => $request->bearerToken() !== null
            ? 'key:'.hash('sha256', (string) $request->bearerToken())
            : 'ip:'.$request->ip();

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by('mcp|'.$bucket($request)));
        RateLimiter::for('buddy-api', fn (Request $request) => Limit::perMinute(60)->by('api|'.$bucket($request)));
        RateLimiter::for('buddy-admin', fn (Request $request) => Limit::perMinute(10)->by('admin|'.$bucket($request)));
    }
}
