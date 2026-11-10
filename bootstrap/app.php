<?php

use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'active' => EnsureAccountIsActive::class,
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('admin', 'admin/*') ? route('admin.login') : route('login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();