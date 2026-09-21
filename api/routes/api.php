<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Documents\StepController;
use App\Http\Controllers\Media\AssetController;
use App\Http\Controllers\Recordings\RecordingController;
use App\Http\Controllers\Spaces\FolderController;
use App\Http\Controllers\Spaces\SpaceController;
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

            Route::get('/spaces', [SpaceController::class, 'index']);
            Route::post('/spaces', [SpaceController::class, 'store']);
            Route::get('/spaces/{id}', [SpaceController::class, 'show']);
            Route::patch('/spaces/{id}', [SpaceController::class, 'update']);
            Route::get('/spaces/{id}/members', [SpaceController::class, 'members']);
            Route::post('/spaces/{id}/members', [SpaceController::class, 'grant']);
            Route::delete('/spaces/{id}/members/{user_id}', [SpaceController::class, 'revoke']);

            Route::get('/folders', [FolderController::class, 'index']);
            Route::post('/folders', [FolderController::class, 'store']);
            Route::patch('/folders/{id}', [FolderController::class, 'update']);
            Route::delete('/folders/{id}', [FolderController::class, 'destroy']);

            Route::get('/templates', [DocumentController::class, 'templates']);
            Route::get('/documents', [DocumentController::class, 'index']);
            Route::post('/documents', [DocumentController::class, 'store']);
            Route::get('/documents/{id}', [DocumentController::class, 'show']);
            Route::get('/documents/{id}/published', [DocumentController::class, 'published']);
            Route::patch('/documents/{id}', [DocumentController::class, 'update']);
            Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);
            Route::post('/documents/{id}/duplicate', [DocumentController::class, 'duplicate']);
            Route::post('/documents/{id}/move', [DocumentController::class, 'move']);

            Route::get('/documents/{id}/steps', [StepController::class, 'index']);
            Route::post('/documents/{id}/steps', [StepController::class, 'store']);
            Route::post('/documents/{id}/steps/reorder', [StepController::class, 'reorder']);
            Route::patch('/documents/{id}/steps/{step_id}', [StepController::class, 'update']);
            Route::delete('/documents/{id}/steps/{step_id}', [StepController::class, 'destroy']);

            Route::post('/recordings/upload-url', [RecordingController::class, 'uploadUrl']);
            Route::post('/recordings', [RecordingController::class, 'store']);
            Route::get('/recordings', [RecordingController::class, 'index']);
            Route::get('/recordings/{id}', [RecordingController::class, 'show']);
            Route::patch('/recordings/{id}', [RecordingController::class, 'update']);
            Route::get('/recordings/{id}/playback-url', [RecordingController::class, 'playbackUrl']);
            Route::post('/recordings/{id}/retry', [RecordingController::class, 'retry']);
            Route::post('/recordings/{id}/generate', [RecordingController::class, 'generate']);
            Route::delete('/recordings/{id}', [RecordingController::class, 'destroy']);
            Route::get('/assets/{id}/url', [AssetController::class, 'url']);
        });
    });
});
