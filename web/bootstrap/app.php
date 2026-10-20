<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the demo tunnel (docs/demo-tunnel.md) Caddy forwards from loopback: trust its
        // X-Forwarded-* headers so Laravel builds https:// URLs. Only a local process can be a proxy,
        // and env() is not loaded yet at this point, so this is not configurable.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
