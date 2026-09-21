<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Models\Space;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

final class WorkspaceTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    public function test_creating_a_workspace_makes_the_creator_admin_of_it_and_of_a_general_space_on_free(): void
    {
        $user = User::create(['name' => 'Ana', 'email' => 'ana@example.test']);

        $res = $this->actingAs($user)->postJson('/api/v1/workspaces', ['name' => 'Northgate Ops']);
        $res->assertStatus(201)->assertJsonPath('data.plan', 'free')->assertJsonPath('data.role', 'admin')->assertJsonPath('data.slug', 'northgate-ops');

        $id = $res->json('data.id');
        $this->assertSame('admin', $user->roleIn($id));
        app(CurrentWorkspace::class)->runAs($id, function (): void {
            $this->assertSame(['General'], Space::query()->pluck('name')->all());
        });
        $this->assertFalse(Workspace::query()->findOrFail($id)->settings['self_approval']);
    }

    public function test_slug_collisions_are_reported_live_and_resolved_on_create(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $this->actingAs($admin)->getJson('/api/v1/workspaces/slug-available?slug=acme')->assertJsonPath('available', false);
        $this->actingAs($admin)->getJson('/api/v1/workspaces/slug-available?slug=acme-2')->assertJsonPath('available', true);
        $this->actingAs($admin)->postJson('/api/v1/workspaces', ['name' => 'Acme'])->assertStatus(201)->assertJsonPath('data.slug', 'acme-2');
        $this->actingAs($admin)->postJson('/api/v1/workspaces', ['name' => 'Acme', 'slug' => 'acme'])->assertStatus(422);
    }

    public function test_workspace_header_must_name_a_workspace_the_caller_belongs_to(): void
    {
        [$a, $adminA] = $this->makeWorkspace('a');
        [$b] = $this->makeWorkspace('b');

        $this->actingAs($adminA)->getJson("/api/v1/workspaces/{$a->id}", $this->wsHeaders($a))->assertOk()->assertJsonPath('data.slug', 'a');
        $this->actingAs($adminA)->getJson("/api/v1/workspaces/{$b->id}", $this->wsHeaders($b))->assertStatus(403);
        $this->actingAs($adminA)->getJson("/api/v1/workspaces/{$b->id}", $this->wsHeaders($a))->assertStatus(403);
        $this->actingAs($adminA)->getJson("/api/v1/workspaces/{$a->id}")->assertStatus(400);
    }

    public function test_only_admins_update_settings(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $editor = $this->addMember($ws, 'ed@example.test', 'editor');

        $this->actingAs($editor)->patchJson("/api/v1/workspaces/{$ws->id}", ['settings' => ['self_approval' => true]], $this->wsHeaders($ws))->assertStatus(403);
        $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}", ['settings' => ['self_approval' => true]], $this->wsHeaders($ws))
            ->assertOk()->assertJsonPath('data.settings.self_approval', true)->assertJsonPath('data.settings.retain_recordings', true);
    }
}
