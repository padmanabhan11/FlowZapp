<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\SpaceMember;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Members and roles (S18). The last administrator cannot be removed or demoted (FR-510). */
final class MemberController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /v1/workspaces/{workspace}/members */
    public function index(Workspace $workspace): JsonResponse
    {
        abort_unless($workspace->id === $this->current->id(), 403, 'Not permitted.');

        $rows = WorkspaceMember::query()->with('user:id,name,email,avatar_path')->orderBy('created_at')->get();

        return response()->json(['data' => $rows->map(fn (WorkspaceMember $m) => [
            'user_id' => $m->user_id, 'name' => $m->user?->name, 'email' => $m->user?->email,
            'role' => $m->role, 'joined_at' => $m->joined_at,
        ])]);
    }

    /** PATCH /v1/workspaces/{workspace}/members/{user_id}  body { role } */
    public function update(Request $request, Workspace $workspace, string $userId): JsonResponse
    {
        $this->guard($workspace);
        $data = $request->validate(['role' => ['required', Rule::in(WorkspaceMember::ROLES)]]);

        /** @var WorkspaceMember $member */
        $member = WorkspaceMember::query()->where('user_id', $userId)->firstOrFail();

        if ($member->role === 'admin' && $data['role'] !== 'admin' && $this->adminCount() <= 1) {
            return $this->lastAdmin();
        }

        $from = $member->role;
        $member->forceFill(['role' => $data['role']])->save();
        Audit::record('member.role_changed', 'user', $userId, ['from' => $from, 'to' => $data['role']]);

        return response()->json(['data' => ['user_id' => $userId, 'role' => $member->role]]);
    }

    /** DELETE /v1/workspaces/{workspace}/members/{user_id} — immediate revocation (FR-507, BR-20). */
    public function destroy(Workspace $workspace, string $userId): JsonResponse
    {
        $this->guard($workspace);

        /** @var WorkspaceMember $member */
        $member = WorkspaceMember::query()->where('user_id', $userId)->firstOrFail();
        if ($member->role === 'admin' && $this->adminCount() <= 1) {
            return $this->lastAdmin();
        }

        DB::transaction(function () use ($member, $userId): void {
            SpaceMember::query()->where('user_id', $userId)->delete();
            $member->delete();
            // Retrieval index scope recalculation hooks in here once M3 lands (FR-615).
            Audit::record('member.removed', 'user', $userId, ['role' => $member->role]);
        });

        return response()->json(['data' => ['user_id' => $userId, 'removed' => true]]);
    }

    private function adminCount(): int
    {
        return WorkspaceMember::query()->where('role', 'admin')->count();
    }

    private function lastAdmin(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'last_admin',
            'message' => 'This is the only administrator. Make someone else an admin first.',
        ]], 422);
    }

    private function guard(Workspace $workspace): void
    {
        abort_unless($workspace->id === $this->current->id(), 403, 'Not permitted.');
        $this->authorize('workspace-admin');
    }
}
