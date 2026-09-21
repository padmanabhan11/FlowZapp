<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\ResolveWorkspace;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\CurrentWorkspace;

/** Test helpers that build a workspace the way WorkspaceController::store does. */
trait ActsInWorkspace
{
    protected function makeWorkspace(string $slug = 'acme', string $plan = 'free', ?User $admin = null): array
    {
        $admin ??= User::create(['name' => 'Ana', 'email' => "$slug-admin@example.test"]);
        $workspace = Workspace::create(['name' => ucfirst($slug), 'slug' => $slug, 'plan' => $plan, 'settings' => config('flowzapp.workspace_defaults')]);
        app(CurrentWorkspace::class)->runAs($workspace->id, function () use ($admin): void {
            WorkspaceMember::create(['user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
            $space = Space::create(['name' => 'General', 'created_by' => $admin->id]);
            SpaceMember::create(['space_id' => $space->id, 'user_id' => $admin->id, 'role' => 'admin']);
        });
        app(CurrentWorkspace::class)->set(null);

        return [$workspace, $admin];
    }

    protected function addMember(Workspace $workspace, string $email, string $role = 'editor'): User
    {
        $user = User::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email]);
        app(CurrentWorkspace::class)->runAs($workspace->id, fn () => WorkspaceMember::create(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]));

        return $user;
    }

    /** @return array<string, string> */
    protected function wsHeaders(Workspace $workspace): array
    {
        return [ResolveWorkspace::HEADER => $workspace->id, 'Accept' => 'application/json'];
    }
}
