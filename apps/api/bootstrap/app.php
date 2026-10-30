<?php

use App\Http\Middleware\EnforceSafeMode;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->api(prepend: [EnforceSafeMode::class]);
        $middleware->alias(['idem' => RequireIdempotencyKey::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Never leak stack traces or secrets in API responses (B15).
        $exceptions->dontFlash(['password', 'pin', 'approval.pin', 'current_password']);
    })->create();
