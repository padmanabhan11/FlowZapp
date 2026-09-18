<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Support\Facades\Route;

/*
 | /v1 — every tenant-scoped route sits behind auth:sanctum + ResolveWorkspace.
 | Routes that legitimately have no tenant yet (list my workspaces, create a
 | workspace, accept an invite) are the only ones outside the group.
 */
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/workspaces', fn () => request()->user()->workspaces()->get(['workspaces.id', 'name', 'slug', 'plan']));

    Route::middleware(ResolveWorkspace::class)->group(function (): void {
        Route::get('/spaces', fn () => \App\Models\Space::query()->orderBy('name')->get());
    });
});
