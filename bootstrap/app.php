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
        $middleware->alias([
            'menu.access' => \App\Http\Middleware\EnsureMenuAccess::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'production' => \App\Http\Middleware\EnsureProductionStaff::class,
            'panel' => \App\Http\Middleware\SetPanelMode::class,
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();