<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spaces;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\WorkspaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Spaces (F3, A4/A5). The list is already permission-scoped: the sidebar renders only what comes back (S5). */
final class SpaceController extends Controller
{
    /** GET /v1/spaces — spaces visible to the caller (A5). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Space::query()->orderBy('name');
        if ($request->attributes->get('workspace_role') !== 'admin') {
            $memberOf = SpaceMember::query()->where('user_id', $user->id)->pluck('space_id');
            $q->whereIn('id', $memberOf);
        }

        return response()->json(['data' => $q->get()->map(fn (Space $s) => $this->present($s))]);
    }

    /** POST /v1/spaces — admin. Creator becomes space admin. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Space::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', Rule::unique('spaces', 'name')->where('workspace_id', app(CurrentWorkspace::class)->id())],
            'description' => ['nullable', 'string', 'max:500'],
            'is_handbook' => ['sometimes', 'boolean'],
        ]);

        $space = Space::create($data + ['created_by' => $request->user()->id]);
        SpaceMember::create(['space_id' => $space->id, 'user_id' => $request->user()->id, 'role' => 'admin']);
        Audit::record('space.created', 'space', $space->id, ['name' => $space->name]);

        return response()->json(['data' => $this->present($space)], 201);
    }

    /** GET /v1/spaces/{id} */
    public function show(string $id): JsonResponse
    {
        $space = Space::query()->findOrFail($id);
        $this->authorize('view', $space);

        return response()->json(['data' => $this->present($space)]);
    }

    /** PATCH /v1/spaces/{id} — rename, description, is_handbook (space admin or workspace admin). */
    public function update(Request $request, string $id): JsonResponse
    {
        $space = Space::query()->findOrFail($id);
        $this->authorize('manage', $space);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120', Rule::unique('spaces', 'name')->ignore($space->id)->where('workspace_id', $space->workspace_id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_handbook' => ['sometimes', 'boolean'],
        ]);
        $space->fill($data)->save();
        Audit::record('space.updated', 'space', $space->id, $data);

        return response()->json(['data' => $this->present($space)]);
    }

    /** GET /v1/spaces/{id}/members — S19 member list with the source of each role. */
    public function members(string $id): JsonResponse
    {
        $space = Space::query()->findOrFail($id);
        $this->authorize('view', $space);

        $explicit = SpaceMember::query()->where('space_id', $space->id)->with('user:id,name,email')->get();
        $admins = WorkspaceMember::query()->where('role', 'admin')->with('user:id,name,email')->get()
            ->reject(fn (WorkspaceMember $m) => $explicit->contains('user_id', $m->user_id));

        $rows = $explicit->map(fn (SpaceMember $m) => [
            'user_id' => $m->user_id, 'name' => $m->user?->name, 'email' => $m->user?->email, 'role' => $m->role, 'source' => 'space',
        ])->concat($admins->map(fn (WorkspaceMember $m) => [
            'user_id' => $m->user_id, 'name' => $m->user?->name, 'email' => $m->user?->email, 'role' => 'admin', 'source' => 'workspace',
        ]));

        return response()->json(['data' => $rows->values()]);
    }

    /** POST /v1/spaces/{id}/members  body { user_id, role } — grant or change (upsert). */
    public function grant(Request $request, string $id): JsonResponse
    {
        $space = Space::query()->findOrFail($id);
        $this->authorize('manage', $space);
        $data = $request->validate([
            'user_id' => ['required', 'string', 'size:26'],
            'role' => ['required', Rule::in(WorkspaceMember::ROLES)],
        ]);
        // Must already be a workspace member — spaces never bypass the workspace boundary.
        abort_unless(WorkspaceMember::query()->where('user_id', $data['user_id'])->exists(), 422, 'Not a member of this workspace.');

        $m = SpaceMember::query()->updateOrCreate(['space_id' => $space->id, 'user_id' => $data['user_id']], ['role' => $data['role']]);
        Audit::record('space.member_granted', 'space', $space->id, ['user_id' => $data['user_id'], 'role' => $data['role']]);

        return response()->json(['data' => ['user_id' => $m->user_id, 'role' => $m->role, 'source' => 'space']]);
    }

    /** DELETE /v1/spaces/{id}/members/{user_id} — immediate revocation (FR-507). */
    public function revoke(string $id, string $userId): JsonResponse
    {
        $space = Space::query()->findOrFail($id);
        $this->authorize('manage', $space);
        SpaceMember::query()->where('space_id', $space->id)->where('user_id', $userId)->delete();
        Audit::record('space.member_revoked', 'space', $space->id, ['user_id' => $userId]);

        return response()->json(['data' => ['user_id' => $userId, 'removed' => true]]);
    }

    /** @return array<string, mixed> */
    private function present(Space $s): array
    {
        return $s->only(['id', 'name', 'description', 'is_handbook', 'created_at', 'updated_at']);
    }
}
