<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Workspaces\InviteController;
use App\Http\Controllers\Workspaces\MemberController;
use App\Http\Controllers\Workspaces\WorkspaceController;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Support\Facades\Route;

/*
 | /v1 — 05-API-Specification. Every tenant-scoped route sits behind
 | auth:sanctum + ResolveWorkspace. Routes with no tenant yet are the only ones
 | outside that group: sign-in, my workspaces, create workspace, accept invite.
 */
Route::prefix('v1')->group(function (): void {
    // Auth (no session yet)
    Route::post('/auth/magic-link', [MagicLinkController::class, 'request'])->middleware('throttle:magic-link');
    Route::get('/auth/magic-link/{nonce}', [MagicLinkController::class, 'consume'])->middleware('signed')->name('auth.magic.consume');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [MagicLinkController::class, 'logout']);
        Route::get('/auth/me', [MagicLinkController::class, 'me']);

        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::get('/workspaces/slug-available', [WorkspaceController::class, 'slugAvailable']);
        Route::post('/workspaces', [WorkspaceController::class, 'store']);
        Route::post('/invites/accept', [InviteController::class, 'accept']);

        Route::middleware(ResolveWorkspace::class)->group(function (): void {
            Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);
            Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update']);

            Route::get('/workspaces/{workspace}/members', [MemberController::class, 'index']);
            Route::patch('/workspaces/{workspace}/members/{user_id}', [MemberController::class, 'update']);
            Route::delete('/workspaces/{workspace}/members/{user_id}', [MemberController::class, 'destroy']);

            Route::get('/workspaces/{workspace}/invites', [InviteController::class, 'index']);
            Route::post('/workspaces/{workspace}/invites', [InviteController::class, 'store']);
            Route::post('/workspaces/{workspace}/invites/{invite_id}/resend', [InviteController::class, 'resend']);
            Route::delete('/workspaces/{workspace}/invites/{invite_id}', [InviteController::class, 'destroy']);

            Route::get('/spaces', fn () => response()->json(['data' => \App\Models\Space::query()->orderBy('name')->get(['id', 'name', 'description', 'is_handbook'])]));
        });
    });
});
