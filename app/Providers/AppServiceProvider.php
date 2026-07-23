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
        //
    }
}
