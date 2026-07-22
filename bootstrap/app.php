<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\ValidateMcpOrigin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Azure Container Apps ingress (Envoy) fronts the app; without
        // this, Request::ip() is the ingress pod IP and every IP-keyed
        // throttle shares one bucket. Ingress is the only network path.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'auth.buddy' => AuthenticateApiKey::class,
            'mcp.origin' => ValidateMcpOrigin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
