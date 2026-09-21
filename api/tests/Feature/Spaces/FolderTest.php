<?php

declare(strict_types=1);

namespace Tests\Feature\Spaces;

use App\Models\Folder;
use App\Models\Space;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

final class FolderTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private function mk(array $ws, string $name, ?string $parent = null): string
    {
        [$w, $admin] = $ws;
        $spaceId = app(CurrentWorkspace::class)->runAs($w->id, fn () => Space::query()->firstOrFail()->id);
        $res = $this->actingAs($admin)->postJson('/api/v1/folders', ['space_id' => $spaceId, 'name' => $name, 'parent_id' => $parent], $this->wsHeaders($w));
        $res->assertStatus(201);

        return $res->json('data.id');
    }

    public function test_nesting_is_capped_at_five_levels(): void
    {
        $ws = $this->makeWorkspace('acme');
        $parent = null;
        for ($i = 0; $i <= Folder::MAX_DEPTH; $i++) {
            $parent = $this->mk($ws, "L$i", $parent);  // depths 0..5
        }
        [$w, $admin] = $ws;
        $spaceId = app(CurrentWorkspace::class)->runAs($w->id, fn () => Space::query()->firstOrFail()->id);
        $this->actingAs($admin)->postJson('/api/v1/folders', ['space_id' => $spaceId, 'name' => 'L6', 'parent_id' => $parent], $this->wsHeaders($w))
            ->assertStatus(422)->assertJsonPath('error.code', 'folder_too_deep');
    }

    public function test_moving_a_folder_moves_its_subtree_and_recomputes_depth(): void
    {
        $ws = $this->makeWorkspace('acme');
        [$w, $admin] = $ws;
        $a = $this->mk($ws, 'A');
        $b = $this->mk($ws, 'B');
        $b1 = $this->mk($ws, 'B1', $b);
        $b11 = $this->mk($ws, 'B11', $b1);

        $this->actingAs($admin)->patchJson("/api/v1/folders/{$b}", ['parent_id' => $a], $this->wsHeaders($w))->assertOk()->assertJsonPath('data.depth', 1);
        app(CurrentWorkspace::class)->runAs($w->id, function () use ($b1, $b11): void {
            $this->assertSame(2, Folder::query()->findOrFail($b1)->depth);
            $this->assertSame(3, Folder::query()->findOrFail($b11)->depth);
        });

        // Cannot move a folder inside its own subtree.
        $this->actingAs($admin)->patchJson("/api/v1/folders/{$a}", ['parent_id' => $b11], $this->wsHeaders($w))->assertStatus(422);
    }

    public function test_deleting_requires_a_strategy_and_move_reparents_children(): void
    {
        $ws = $this->makeWorkspace('acme');
        [$w, $admin] = $ws;
        $keep = $this->mk($ws, 'Keep');
        $gone = $this->mk($ws, 'Gone');
        $child = $this->mk($ws, 'Child', $gone);

        $this->actingAs($admin)->deleteJson("/api/v1/folders/{$gone}", [], $this->wsHeaders($w))->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/v1/folders/{$gone}?strategy=move&target_folder_id={$keep}", [], $this->wsHeaders($w))->assertOk();

        app(CurrentWorkspace::class)->runAs($w->id, function () use ($gone, $child, $keep): void {
            $this->assertNull(Folder::query()->find($gone));
            $c = Folder::query()->findOrFail($child);
            $this->assertSame($keep, $c->parent_id);
            $this->assertSame(1, $c->depth);
        });
    }

    public function test_readers_cannot_create_folders_and_non_members_cannot_see_the_tree(): void
    {
        $ws = $this->makeWorkspace('acme');
        [$w, $admin] = $ws;
        $spaceId = app(CurrentWorkspace::class)->runAs($w->id, fn () => Space::query()->firstOrFail()->id);
        $this->mk($ws, 'Clients');

        $reader = $this->addMember($w, 'r@example.test', 'reader');
        $this->actingAs($reader)->getJson("/api/v1/folders?space_id={$spaceId}", $this->wsHeaders($w))->assertStatus(403);

        $this->actingAs($admin)->postJson("/api/v1/spaces/{$spaceId}/members", ['user_id' => $reader->id, 'role' => 'reader'], $this->wsHeaders($w))->assertOk();
        $this->actingAs($reader)->getJson("/api/v1/folders?space_id={$spaceId}", $this->wsHeaders($w))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($reader)->postJson('/api/v1/folders', ['space_id' => $spaceId, 'name' => 'Nope'], $this->wsHeaders($w))->assertStatus(403);
    }
}
