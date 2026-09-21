<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: the Angular app authenticates with the session cookie + CSRF.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 05-API-Specification error envelope: { "error": { code, message, details } }
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $r) {
            if ($r->expectsJson() || $r->is('api/*')) {
                return response()->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'details' => $e->errors()]], 422);
            }
        });
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $r) {
            if ($r->expectsJson() || $r->is('api/*')) {
                return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in required.']], 401);
            }
        });
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, \Illuminate\Http\Request $r) {
            if ($r->expectsJson() || $r->is('api/*')) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Not permitted.']], 403);
            }
        });
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, \Illuminate\Http\Request $r) {
            // Do not leak existence: a tenant-scoped miss reads the same as a permission miss.
            if ($r->expectsJson() || $r->is('api/*')) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Not permitted.']], 403);
            }
        });
    })->create();
