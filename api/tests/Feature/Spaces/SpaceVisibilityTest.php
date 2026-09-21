<?php

declare(strict_types=1);

namespace Tests\Feature\Spaces;

use App\Models\Space;
use App\Models\SpaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** A5: a user sees only the spaces they are a member of, everywhere. Admins see all. Default deny (FR-502). */
final class SpaceVisibilityTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    public function test_new_member_sees_no_spaces_until_granted_and_cannot_fetch_one_directly(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $reader = $this->addMember($ws, 'r@example.test', 'reader');
        $generalId = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);

        $this->actingAs($reader)->getJson('/api/v1/spaces', $this->wsHeaders($ws))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($reader)->getJson("/api/v1/spaces/{$generalId}", $this->wsHeaders($ws))->assertStatus(403);
        $this->actingAs($admin)->getJson('/api/v1/spaces', $this->wsHeaders($ws))->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($admin)->postJson("/api/v1/spaces/{$generalId}/members", ['user_id' => $reader->id, 'role' => 'reader'], $this->wsHeaders($ws))->assertOk();
        $this->actingAs($reader)->getJson('/api/v1/spaces', $this->wsHeaders($ws))->assertJsonCount(1, 'data');
        $this->actingAs($reader)->getJson("/api/v1/spaces/{$generalId}", $this->wsHeaders($ws))->assertOk();

        $this->actingAs($admin)->deleteJson("/api/v1/spaces/{$generalId}/members/{$reader->id}", [], $this->wsHeaders($ws))->assertOk();
        $this->actingAs($reader)->getJson("/api/v1/spaces/{$generalId}", $this->wsHeaders($ws))->assertStatus(403);
    }

    public function test_only_workspace_admins_create_spaces_and_names_are_unique_per_workspace(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $editor = $this->addMember($ws, 'e@example.test', 'editor');

        $this->actingAs($editor)->postJson('/api/v1/spaces', ['name' => 'Finance'], $this->wsHeaders($ws))->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/v1/spaces', ['name' => 'Finance'], $this->wsHeaders($ws))->assertStatus(201);
        $this->actingAs($admin)->postJson('/api/v1/spaces', ['name' => 'Finance'], $this->wsHeaders($ws))->assertStatus(422);

        // Same name in another workspace is fine.
        [$other, $otherAdmin] = $this->makeWorkspace('other');
        $this->actingAs($otherAdmin)->postJson('/api/v1/spaces', ['name' => 'Finance'], $this->wsHeaders($other))->assertStatus(201);
    }

    public function test_space_members_view_lists_workspace_admins_with_their_source(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $generalId = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);
        $editor = $this->addMember($ws, 'e@example.test', 'editor');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => SpaceMember::create(['space_id' => $generalId, 'user_id' => $editor->id, 'role' => 'editor']));

        $res = $this->actingAs($admin)->getJson("/api/v1/spaces/{$generalId}/members", $this->wsHeaders($ws))->assertOk();
        $rows = collect($res->json('data'))->keyBy('user_id');
        $this->assertSame('space', $rows[$admin->id]['source']);   // explicit space admin from makeWorkspace
        $this->assertSame('editor', $rows[$editor->id]['role']);
    }
}
