<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Space;
use App\Models\SpaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

final class FolderPermissionTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    public function test_none_override_hides_a_subtree_and_the_inspector_explains_why(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $h = $this->wsHeaders($ws);
        $sid = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);
        $reader = $this->addMember($ws, 'r@example.test', 'reader');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => SpaceMember::create(['space_id' => $sid, 'user_id' => $reader->id, 'role' => 'reader']));

        $pub = $this->actingAs($admin)->postJson('/api/v1/folders', ['space_id' => $sid, 'name' => 'Public'], $h)->json('data.id');
        $priv = $this->actingAs($admin)->postJson('/api/v1/folders', ['space_id' => $sid, 'name' => 'Board'], $h)->json('data.id');
        $sub = $this->actingAs($admin)->postJson('/api/v1/folders', ['space_id' => $sid, 'name' => 'Comp', 'parent_id' => $priv], $h)->json('data.id');
        $d1 = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'folder_id' => $pub, 'title' => 'Refunds'], $h)->json('data.id');
        $d2 = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'folder_id' => $sub, 'title' => 'Board compensation'], $h)->json('data.id');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => \App\Models\Document::query()->whereIn('id', [$d1, $d2])->update(['state' => 'approved']));

        $this->assertCount(2, $this->actingAs($reader)->getJson('/api/v1/documents', $h)->json('data'));
        $this->actingAs($admin)->putJson("/api/v1/folders/{$priv}/permissions/{$reader->id}", ['role' => 'none'], $h)->assertOk();

        $this->assertSame(['Refunds'], array_column($this->actingAs($reader)->getJson('/api/v1/documents', $h)->json('data'), 'title'));
        $this->actingAs($reader)->getJson("/api/v1/documents/{$d2}", $h)->assertStatus(403);
        $this->actingAs($reader)->getJson("/api/v1/documents/{$d1}", $h)->assertOk();

        $chain = $this->actingAs($admin)->getJson("/api/v1/folders/{$sub}/permissions?user_id={$reader->id}", $h)->assertOk()->json('data');
        $this->assertNull($chain['role']);
        $levels = array_column($chain['chain'], 'level');
        $this->assertSame(['workspace', 'space', 'folder', 'folder', 'result'], $levels);
        $this->assertSame('none', $chain['chain'][2]['role']);      // Board: override
        $this->assertSame('Inherited', $chain['chain'][3]['note']); // Comp: inherits the none

        // Remove the override → inherits reader again.
        $this->actingAs($admin)->deleteJson("/api/v1/folders/{$priv}/permissions/{$reader->id}", [], $h)->assertOk();
        $this->actingAs($reader)->getJson("/api/v1/documents/{$d2}", $h)->assertOk();
        $this->actingAs($reader)->putJson("/api/v1/folders/{$priv}/permissions/{$reader->id}", ['role' => 'editor'], $h)->assertStatus(403);
    }

    public function test_override_can_grant_a_non_member_one_folder_and_raise_an_editor_to_approver(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $h = $this->wsHeaders($ws);
        $sid = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);
        $guest = $this->addMember($ws, 'g@example.test', 'guest');           // not a space member
        $editor = $this->addMember($ws, 'e@example.test', 'editor');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => SpaceMember::create(['space_id' => $sid, 'user_id' => $editor->id, 'role' => 'editor']));

        $shared = $this->actingAs($admin)->postJson('/api/v1/folders', ['space_id' => $sid, 'name' => 'Shared with client'], $h)->json('data.id');
        $d = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'folder_id' => $shared, 'title' => 'Handoff'], $h)->json('data.id');
        $other = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'title' => 'Internal'], $h)->json('data.id');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => \App\Models\Document::query()->whereIn('id', [$d, $other])->update(['state' => 'approved']));

        $this->assertCount(0, $this->actingAs($guest)->getJson('/api/v1/documents', $h)->json('data'));
        $this->actingAs($admin)->putJson("/api/v1/folders/{$shared}/permissions/{$guest->id}", ['role' => 'guest'], $h)->assertOk();
        $this->assertSame(['Handoff'], array_column($this->actingAs($guest)->getJson('/api/v1/documents', $h)->json('data'), 'title'));
        $this->actingAs($guest)->getJson("/api/v1/documents/{$d}", $h)->assertOk();
        $this->actingAs($guest)->getJson("/api/v1/documents/{$other}", $h)->assertStatus(403);

        // Editor raised to approver in one folder can archive there but not elsewhere.
        $this->actingAs($editor)->postJson("/api/v1/documents/{$d}/archive", [], $h)->assertStatus(403);
        $this->actingAs($admin)->putJson("/api/v1/folders/{$shared}/permissions/{$editor->id}", ['role' => 'approver'], $h)->assertOk();
        $this->actingAs($editor)->postJson("/api/v1/documents/{$d}/archive", [], $h)->assertOk();
        $this->actingAs($editor)->postJson("/api/v1/documents/{$other}/archive", [], $h)->assertStatus(403);

        $eff = $this->actingAs($admin)->getJson("/api/v1/folders/{$shared}/permissions", $h)->assertOk()->json('data');
        $byUser = collect($eff['effective'])->keyBy(fn ($e) => $e['user']['id']);
        $this->assertSame('guest', $byUser[$guest->id]['role']);
        $this->assertSame('folder', $byUser[$guest->id]['source']);
        $this->assertSame('approver', $byUser[$editor->id]['role']);
        $this->assertSame('admin', $byUser[$admin->id]['role']);
        $this->assertCount(2, $eff['overrides']);
    }
}
