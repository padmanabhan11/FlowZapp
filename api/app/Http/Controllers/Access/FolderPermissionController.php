<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Access\Access;
use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Folder overrides and the effective-permission inspector (F10, S19, FR-504, FR-508). */
final class FolderPermissionController extends Controller
{
    /**
     * GET /v1/folders/{id}/permissions[?user_id=]
     * Without user_id: the folder's overrides and every member's effective role here (resolved).
     * With user_id: that person's resolution chain — workspace → space → folder overrides → result.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $folder = Folder::query()->findOrFail($id);
        $space = Space::query()->findOrFail($folder->space_id);
        $this->authorize('view', $space);

        if ($uid = $request->query('user_id')) {
            $this->authorize('manage', $space);
            $user = User::query()->findOrFail((string) $uid);

            return response()->json(['data' => ['user' => $user->only(['id', 'name', 'email'])] + Access::resolve($user, $space, $folder)]);
        }

        $overrides = FolderPermission::query()->where('folder_id', $folder->id)->with(['user:id,name,email', 'setter:id,name'])->get()
            ->map(fn (FolderPermission $p) => ['user' => $p->user?->only(['id', 'name', 'email']), 'role' => $p->role, 'set_by' => $p->setter?->only(['id', 'name']), 'set_at' => $p->updated_at]);

        $candidates = User::query()->whereIn('id', WorkspaceMember::query()->pluck('user_id'))->get(['id', 'name', 'email']);
        $effective = $candidates->map(function (User $u) use ($space, $folder) {
            $r = Access::resolve($u, $space, $folder);
            $source = collect($r['chain'])->filter(fn ($c) => $c['role'] !== null && $c['level'] !== 'result')->last();

            return ['user' => $u->only(['id', 'name', 'email']), 'role' => $r['role'], 'source' => $source['level'] ?? null];
        })->filter(fn ($e) => $e['role'] !== null)->values();

        return response()->json(['data' => ['folder' => $folder->only(['id', 'name', 'space_id', 'parent_id', 'depth']), 'overrides' => $overrides, 'effective' => $effective]]);
    }

    /** PUT /v1/folders/{id}/permissions/{user_id}  body { role: none|approver|editor|reader|guest } */
    public function set(Request $request, string $id, string $userId): JsonResponse
    {
        $folder = Folder::query()->findOrFail($id);
        $space = Space::query()->findOrFail($folder->space_id);
        $this->authorize('manage', $space);
        $data = $request->validate(['role' => ['required', Rule::in(FolderPermission::ROLES)]]);
        abort_unless(WorkspaceMember::query()->where('user_id', $userId)->exists(), 422, 'Not a member of this workspace.');

        $p = FolderPermission::query()->updateOrCreate(['folder_id' => $folder->id, 'user_id' => $userId], ['role' => $data['role'], 'set_by' => $request->user()->id]);
        Audit::record('folder.permission_set', 'folder', $folder->id, ['user_id' => $userId, 'role' => $data['role']]);
        // Retrieval index re-scope hooks in here from M3 (FR-507).

        return response()->json(['data' => ['folder_id' => $folder->id, 'user_id' => $userId, 'role' => $p->role]]);
    }

    /** DELETE /v1/folders/{id}/permissions/{user_id} — remove the override; inherits again. */
    public function unset(string $id, string $userId): JsonResponse
    {
        $folder = Folder::query()->findOrFail($id);
        $space = Space::query()->findOrFail($folder->space_id);
        $this->authorize('manage', $space);
        FolderPermission::query()->where('folder_id', $folder->id)->where('user_id', $userId)->delete();
        Audit::record('folder.permission_removed', 'folder', $folder->id, ['user_id' => $userId]);

        return response()->json(['data' => ['folder_id' => $folder->id, 'user_id' => $userId, 'role' => null]]);
    }

    /** GET /v1/spaces/{id}/access?user_id= — inspector at space level (no folder). */
    public function spaceAccess(Request $request, string $id): JsonResponse
    {
        $space = Space::query()->findOrFail($id);
        $this->authorize('manage', $space);
        $user = User::query()->findOrFail((string) $request->query('user_id'));
        $r = Access::resolve($user, $space);
        $overrides = FolderPermission::query()->where('user_id', $user->id)
            ->whereIn('folder_id', Folder::query()->where('space_id', $space->id)->pluck('id'))->with('folder:id,name')->get()
            ->map(fn (FolderPermission $p) => ['folder' => $p->folder?->only(['id', 'name']), 'role' => $p->role]);
        $memberOf = SpaceMember::query()->where('space_id', $space->id)->where('user_id', $user->id)->exists();

        return response()->json(['data' => ['user' => $user->only(['id', 'name', 'email']), 'space_member' => $memberOf, 'folder_overrides' => $overrides] + $r]);
    }
}
