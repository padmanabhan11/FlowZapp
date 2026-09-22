<?php

declare(strict_types=1);

use App\Http\Controllers\Access\FolderPermissionController;
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\NotificationController;
use App\Http\Controllers\Account\SessionController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Documents\StepController;
use App\Http\Controllers\Governance\ApprovalController;
use App\Http\Controllers\Handbook\AcknowledgementController;
use App\Http\Controllers\Handbook\HandbookController;
use App\Http\Controllers\Media\AssetController;
use App\Http\Controllers\Recordings\RecordingController;
use App\Http\Controllers\Retrieval\ChatController;
use App\Http\Controllers\Retrieval\SearchController;
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
    Route::get('/auth/magic-link/{nonce}', [MagicLinkController::class, 'consume'])->middleware(['web', 'signed'])->name('auth.magic.consume'); // web: needs the session store

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [MagicLinkController::class, 'logout'])->middleware('web');
        Route::get('/auth/me', [MagicLinkController::class, 'me']);
        Route::patch('/account', [AccountController::class, 'update']);
        Route::get('/auth/sessions', [SessionController::class, 'index'])->middleware('web');
        Route::delete('/auth/sessions/{id}', [SessionController::class, 'destroy'])->middleware('web');
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read', [NotificationController::class, 'read']);

        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::get('/workspaces/slug-available', [WorkspaceController::class, 'slugAvailable']);
        Route::post('/workspaces', [WorkspaceController::class, 'store']);
        Route::post('/invites/accept', [InviteController::class, 'accept']);

        Route::middleware(ResolveWorkspace::class)->group(function (): void {
            Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);
            Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update']);
            Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy']);
            Route::post('/workspaces/{workspace}/cancel-deletion', [WorkspaceController::class, 'cancelDeletion']);
            Route::get('/account/notifications', [AccountController::class, 'notifications']);
            Route::patch('/account/notifications', [AccountController::class, 'updateNotifications']);

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
            Route::get('/folders/{id}/permissions', [FolderPermissionController::class, 'show']);
            Route::put('/folders/{id}/permissions/{user_id}', [FolderPermissionController::class, 'set']);
            Route::delete('/folders/{id}/permissions/{user_id}', [FolderPermissionController::class, 'unset']);
            Route::get('/spaces/{id}/access', [FolderPermissionController::class, 'spaceAccess']);

            Route::get('/templates', [DocumentController::class, 'templates']);
            Route::get('/documents', [DocumentController::class, 'index']);
            Route::post('/documents', [DocumentController::class, 'store']);
            Route::get('/documents/{id}', [DocumentController::class, 'show']);
            Route::get('/documents/{id}/published', [DocumentController::class, 'published']);
            Route::patch('/documents/{id}', [DocumentController::class, 'update']);
            Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);
            Route::post('/documents/{id}/duplicate', [DocumentController::class, 'duplicate']);
            Route::post('/documents/{id}/move', [DocumentController::class, 'move']);

            Route::get('/documents/{id}/submit-check', [ApprovalController::class, 'submitCheck']);
            Route::post('/documents/{id}/submit', [ApprovalController::class, 'submit']);
            Route::post('/documents/{id}/approve', [ApprovalController::class, 'approve']);
            Route::post('/documents/{id}/request-changes', [ApprovalController::class, 'requestChanges']);
            Route::post('/documents/{id}/archive', [ApprovalController::class, 'archive']);
            Route::get('/documents/{id}/approvals', [ApprovalController::class, 'history']);
            Route::get('/documents/{id}/review', [ApprovalController::class, 'review']);
            Route::get('/documents/{id}/versions', [ApprovalController::class, 'versions']);
            Route::get('/documents/{id}/versions/{version_id}', [ApprovalController::class, 'version']);
            Route::get('/documents/{id}/diff', [ApprovalController::class, 'diff']);
            Route::post('/documents/{id}/versions/{version_id}/restore', [ApprovalController::class, 'restore']);

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

            Route::post('/search', [SearchController::class, 'search']);
            Route::post('/chat/sessions', [ChatController::class, 'createSession']);
            Route::get('/chat/sessions', [ChatController::class, 'sessions']);
            Route::get('/chat/sessions/{id}/messages', [ChatController::class, 'messages']);
            Route::post('/chat/sessions/{id}/messages', [ChatController::class, 'ask']);
            Route::post('/chat/messages/{id}/rating', [ChatController::class, 'rate']);
            Route::get('/analytics/knowledge-gaps', [ChatController::class, 'gaps']);
            Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
            Route::get('/audit-log', [AuditLogController::class, 'index']);
            Route::get('/audit-log/export', [AuditLogController::class, 'export']);

            Route::get('/usage', [BillingController::class, 'usage']);
            Route::get('/billing/portal', [BillingController::class, 'portal']);

            Route::get('/handbook', [HandbookController::class, 'index']);
            Route::put('/handbook/order', [HandbookController::class, 'reorder']);
            Route::post('/documents/{id}/acknowledgement-targets', [AcknowledgementController::class, 'targets']);
            Route::post('/documents/{id}/acknowledge', [AcknowledgementController::class, 'acknowledge']);
            Route::get('/acknowledgements', [AcknowledgementController::class, 'index']);
            Route::get('/acknowledgements/export', [AcknowledgementController::class, 'export']);
            Route::post('/acknowledgements/remind', [AcknowledgementController::class, 'remind']);
        });
    });
});
