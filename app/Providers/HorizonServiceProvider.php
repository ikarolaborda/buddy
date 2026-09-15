<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/*
 * The dashboard has no place on the public API surface: Buddy authenticates
 * callers by API key, not by user session, so the gate denies every request
 * outside a local environment. The worker uses Horizon as a process
 * supervisor; operators read queue state through buddy:queue:report and the
 * authenticated scaling endpoint (ADR 0014).
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => false);
    }
}
