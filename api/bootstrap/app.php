<?php

declare(strict_types=1);

use App\Billing\PlanLimitExceeded;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        Integration::handles($exceptions);   // M4-T1: unhandled exceptions to Sentry (no-op without SENTRY_LARAVEL_DSN)

        // 05-API-Specification error envelope: { "error": { code, message, details } }
        $exceptions->render(function (ValidationException $e, Request $r) {
            if ($r->expectsJson() || $r->is('api/*')) {
                return response()->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'details' => $e->errors()]], 422);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) {
            if ($r->expectsJson() || $r->is('api/*')) {
                return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in required.']], 401);
            }
        });
        $exceptions->render(function (AuthorizationException $e, Request $r) {
            if ($r->expectsJson() || $r->is('api/*')) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Not permitted.']], 403);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) {
            if ($r->expectsJson() || $r->is('api/*')) {
                if ($e->getStatusCode() === 404 && $e->getPrevious() instanceof ModelNotFoundException) {
                    // Do not leak existence: a tenant-scoped miss is indistinguishable from a permission miss.
                    return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Not permitted.']], 403);
                }
                if ($e instanceof PlanLimitExceeded) {
                    return response()->json(['error' => ['code' => 'plan_limit_exceeded', 'message' => $e->getMessage(), 'details' => $e->details()]], 429);
                }
                $codes = [400 => 'bad_request', 402 => 'plan_required', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 422 => 'unprocessable', 429 => 'too_many_requests'];

                return response()->json(['error' => ['code' => $codes[$e->getStatusCode()] ?? 'error', 'message' => $e->getMessage() ?: 'Request failed.']], $e->getStatusCode());
            }
        });
        $exceptions->render(function (ModelNotFoundException $e, Request $r) {
            // Do not leak existence: a tenant-scoped miss reads the same as a permission miss.
            if ($r->expectsJson() || $r->is('api/*')) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Not permitted.']], 403);
            }
        });
    })->create();
