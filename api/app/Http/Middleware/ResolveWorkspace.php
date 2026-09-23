<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for the request (05-API-Specification, Conventions):
 * the workspace comes from the authenticated session plus a required
 * X-Workspace-Id header, and is never taken from a request body.
 *
 * A header naming a workspace the caller is not a member of is refused with
 * 403 — not 404, so existence is not leaked.
 */
final class ResolveWorkspace
{
    public const HEADER = 'X-Workspace-Id';

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        /**
         * @var User|null $user
         */
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in required.']], 401);
        }

        $workspaceId = (string) $request->header(self::HEADER, '');
        if ($workspaceId === '' || strlen($workspaceId) !== 26) {
            return response()->json(['error' => [
                'code' => 'workspace_required',
                'message' => self::HEADER.' header is required.',
            ]], 400);
        }

        $role = $user->roleIn($workspaceId);
        if ($role === null) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Not permitted.']], 403);
        }

        $this->current->set($workspaceId);
        $request->attributes->set('workspace_role', $role);

        return $next($request);
    }
}
