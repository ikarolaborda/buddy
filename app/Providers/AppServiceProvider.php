<?php

namespace App\Providers;

use App\Services\EscalationService;
use App\Services\EvaluatorOptimizerService;
use App\Services\QdrantMemoryService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QdrantMemoryService::class);
        $this->app->singleton(EscalationService::class);
        $this->app->singleton(EvaluatorOptimizerService::class);
    }

    public function boot(): void
    {
        //
    }
}
