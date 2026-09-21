<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class WorkspaceController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /v1/workspaces — workspaces the caller belongs to. */
    public function index(Request $request): JsonResponse
    {
        $rows = $request->user()->workspaces()->orderBy('name')->get(['workspaces.id', 'name', 'slug', 'plan']);

        return response()->json(['data' => $rows->map(fn (Workspace $w) => [
            'id' => $w->id, 'name' => $w->name, 'slug' => $w->slug, 'plan' => $w->plan, 'role' => $w->pivot->role,
        ])]);
    }

    /** GET /v1/workspaces/slug-available?slug= — live check for S2. */
    public function slugAvailable(Request $request): JsonResponse
    {
        $slug = Str::slug((string) $request->query('slug', ''));

        return response()->json([
            'slug' => $slug,
            'available' => $slug !== '' && ! Workspace::query()->where('slug', $slug)->exists(),
        ]);
    }

    /**
     * POST /v1/workspaces — create the tenant (FR-104). The creator becomes admin,
     * a default "General" space is created, and the Free plan is assigned (S2 rules).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('workspaces', 'slug')],
        ]);
        $slug = $data['slug'] ?? $this->uniqueSlug($data['name']);
        $user = $request->user();

        $workspace = DB::transaction(function () use ($data, $slug, $user): Workspace { // allowlisted: transaction wrapper, models inside are scoped
            $workspace = Workspace::create([
                'name' => $data['name'],
                'slug' => $slug,
                'plan' => 'free',
                'settings' => config('flowzapp.workspace_defaults'),
            ]);

            $this->current->runAs($workspace->id, function () use ($user): void {
                WorkspaceMember::create(['user_id' => $user->id, 'role' => 'admin', 'joined_at' => now()]);
                $space = Space::create(['name' => 'General', 'created_by' => $user->id]);
                SpaceMember::create(['space_id' => $space->id, 'user_id' => $user->id, 'role' => 'admin']);
            });

            return $workspace;
        });

        return response()->json(['data' => [
            'id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug, 'plan' => $workspace->plan, 'role' => 'admin',
        ]], 201);
    }

    /** GET /v1/workspaces/{workspace} — detail + plan + settings (needs X-Workspace-Id to match). */
    public function show(Workspace $workspace): JsonResponse
    {
        $this->assertCurrent($workspace);

        return response()->json(['data' => $workspace->only(['id', 'name', 'slug', 'plan', 'settings', 'deletion_scheduled_at', 'created_at'])]);
    }

    /** PATCH /v1/workspaces/{workspace} — name, settings (admin). */
    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->assertCurrent($workspace);
        $this->authorize('workspace-admin');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:60'],
            'settings' => ['sometimes', 'array'],
            'settings.self_approval' => ['sometimes', 'boolean'],
            'settings.review_cadence_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:36'],
            'settings.retain_recordings' => ['sometimes', 'boolean'],
            'settings.default_language' => ['sometimes', 'string', 'max:10'],
        ]);

        if (isset($data['settings'])) {
            $data['settings'] = array_merge($workspace->settings ?? [], $data['settings']);
        }
        $workspace->fill($data)->save();
        Audit::record('workspace.settings_updated', 'workspace', $workspace->id, array_keys($data['settings'] ?? []) + (isset($data['name']) ? ['name' => true] : []));

        return response()->json(['data' => $workspace->only(['id', 'name', 'slug', 'plan', 'settings', 'deletion_scheduled_at'])]);
    }

    /**
     * DELETE /v1/workspaces/{workspace}  body { confirm_name } — schedules deletion after a
     * grace period (S23: "Scheduled deletion with a grace period"); nothing is removed now.
     * workspaces:purge-scheduled deletes for real once the date has passed.
     */
    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->assertCurrent($workspace);
        $this->authorize('workspace-admin');
        $data = $request->validate(['confirm_name' => ['required', 'string']]);
        abort_unless($data['confirm_name'] === $workspace->name, 422, 'Type the workspace name exactly to confirm.');

        $days = (int) config('flowzapp.workspace_deletion_grace_days', 14);
        $workspace->forceFill(['deletion_scheduled_at' => now()->addDays($days)])->save();
        Audit::record('workspace.deletion_scheduled', 'workspace', $workspace->id, ['at' => $workspace->deletion_scheduled_at?->toIso8601String()]);

        return response()->json(['data' => ['deletion_scheduled_at' => $workspace->deletion_scheduled_at, 'grace_days' => $days]]);
    }

    /** POST /v1/workspaces/{workspace}/cancel-deletion — any admin, any time before the date. */
    public function cancelDeletion(Workspace $workspace): JsonResponse
    {
        $this->assertCurrent($workspace);
        $this->authorize('workspace-admin');
        $workspace->forceFill(['deletion_scheduled_at' => null])->save();
        Audit::record('workspace.deletion_cancelled', 'workspace', $workspace->id);

        return response()->json(['data' => ['deletion_scheduled_at' => null]]);
    }

    private function assertCurrent(Workspace $workspace): void
    {
        abort_unless($workspace->id === $this->current->id(), 403, 'Not permitted.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        for ($i = 2; Workspace::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }
}
