<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Media\FakeMediaStorage;
use App\Media\MediaStorage;
use App\Models\MediaAsset;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** Epic B completion: B1-T3 images, B1-T5 round-trip fidelity, B5-T3 custom templates, B7 internal links and backlinks. */
final class EditorCompletionTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private Workspace $ws;

    private User $admin;

    private User $editor;

    private User $reader;

    private string $sid;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->ws, $this->admin] = $this->makeWorkspace('acme');
        $this->sid = app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Space::query()->firstOrFail()->id);
        $this->editor = $this->addMember($this->ws, 'ed@example.test', 'editor');
        $this->reader = $this->addMember($this->ws, 'rd@example.test', 'reader');
        app(CurrentWorkspace::class)->runAs($this->ws->id, function (): void {
            SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->editor->id, 'role' => 'editor']);
            SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->reader->id, 'role' => 'reader']);
        });
    }

    private function h(): array
    {
        return $this->wsHeaders($this->ws);
    }

    private function doc(string $title = 'Doc'): string
    {
        return $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => $title], $this->h())->assertStatus(201)->json('data.id');
    }

    public function test_every_block_type_round_trips_exactly(): void
    {
        $id = $this->doc();
        $blocks = [
            ['id' => 'h1', 'type' => 'heading', 'text' => 'Before you start', 'level' => 1],
            ['id' => 'p1', 'type' => 'paragraph', 'text' => 'Line with “quotes”, emoji 🚀 and <tags> kept as text.'],
            ['id' => 'ul', 'type' => 'bullet_list', 'items' => ['One', ['text' => 'Nested', 'level' => 1], ['text' => 'Deeper', 'level' => 2]]],
            ['id' => 'ol', 'type' => 'numbered_list', 'items' => ['First', 'Second']],
            ['id' => 'ck', 'type' => 'checklist', 'items' => [['text' => 'Done', 'checked' => true], ['text' => 'Open', 'checked' => false]]],
            ['id' => 'co', 'type' => 'callout', 'text' => 'Never share the key.', 'variant' => 'danger'],
            ['id' => 'cd', 'type' => 'code', 'text' => "if (x) {\n    y();\n}", 'language' => 'js'],
            ['id' => 'tb', 'type' => 'table', 'rows' => [['Field', 'Value'], ['Region', 'EU'], ['', '']]],
            ['id' => 'dv', 'type' => 'divider'],
            ['id' => 'lk', 'type' => 'link', 'text' => 'https://example.test/policy'],
        ];
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['blocks' => $blocks]], $this->h())->assertOk();
        $back = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}", $this->h())->assertOk()->json('data.content.blocks');
        $this->assertSame(self::canon($blocks), self::canon($back), 'save → load must return the blocks unchanged');

        // A second save of what was loaded is a no-op for content.
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['blocks' => $back]], $this->h())->assertOk();
        $this->assertSame(self::canon($blocks), self::canon($this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}", $this->h())->json('data.content.blocks')));

        // Unknown block types are rejected, never silently stored.
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['blocks' => [['id' => 'x', 'type' => 'html', 'text' => '<b>x</b>']]]], $this->h())->assertStatus(422);
    }

    public function test_images_upload_directly_and_are_served_by_signed_url_after_a_policy_check(): void
    {
        $id = $this->doc();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/assets", ['filename' => 'a.exe', 'mime_type' => 'application/x-msdownload', 'size_bytes' => 10], $this->h())->assertStatus(422);
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/assets", ['filename' => 'big.png', 'mime_type' => 'image/png', 'size_bytes' => 11 * 1024 * 1024], $this->h())->assertStatus(422);
        $this->actingAs($this->reader)->postJson("/api/v1/documents/{$id}/assets", ['filename' => 's.png', 'mime_type' => 'image/png', 'size_bytes' => 100], $this->h())->assertStatus(403);

        $r = $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/assets", ['filename' => 'screen.PNG', 'mime_type' => 'image/png', 'size_bytes' => 2048], $this->h())->assertStatus(201);
        $assetId = $r->json('data.asset_id');
        $this->assertSame('image', $r->json('data.kind'));
        $this->assertSame('PUT', $r->json('data.method'));
        $this->assertStringContainsString("{$this->ws->id}/documents/{$id}/{$assetId}.png", $r->json('data.upload_url'));

        // Not uploaded yet → 409; after the PUT lands → complete returns a signed URL.
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/assets/{$assetId}/complete", [], $this->h())->assertStatus(409);
        /** @var FakeMediaStorage $storage */
        $storage = app(MediaStorage::class);
        $key = app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => MediaAsset::query()->findOrFail($assetId)->storage_key);
        $storage->objects[$key] = 2000;
        $done = $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/assets/{$assetId}/complete", [], $this->h())->assertOk();
        $this->assertStringContainsString('X-Amz-Expires=900', $done->json('data.url'));

        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['blocks' => [['id' => 'im', 'type' => 'image', 'asset_id' => $assetId, 'text' => 'The settings page']]]], $this->h())->assertOk();
        $this->actingAs($this->editor)->getJson("/api/v1/assets/{$assetId}/url", $this->h())->assertOk();
        // The reader cannot see a draft, so cannot fetch its image either.
        $this->actingAs($this->reader)->getJson("/api/v1/assets/{$assetId}/url", $this->h())->assertStatus(403);
    }

    public function test_workspace_templates_are_saved_from_a_document_and_used_to_create_new_ones(): void
    {
        $id = $this->doc('Monthly close');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Close the books.', 'blocks' => [
            ['id' => 'p', 'type' => 'paragraph', 'text' => 'Keep'], ['id' => 'i', 'type' => 'image', 'asset_id' => str_repeat('A', 26)],
        ]]], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => 'Reconcile accounts', 'is_critical' => true], $this->h())->assertStatus(201);

        $this->actingAs($this->reader)->postJson('/api/v1/templates', ['document_id' => $id, 'name' => 'Close'], $this->h())->assertStatus(403);
        $t = $this->actingAs($this->editor)->postJson('/api/v1/templates', ['document_id' => $id, 'name' => 'Month-end close', 'description' => 'Finance'], $this->h())->assertStatus(201)->json('data');
        $this->assertTrue($t['custom']);
        $this->assertSame(self::canon([['id' => 'p', 'type' => 'paragraph', 'text' => 'Keep']]), self::canon($t['content']['blocks']), 'image blocks are stripped from templates');
        $this->actingAs($this->editor)->postJson('/api/v1/templates', ['document_id' => $id, 'name' => 'Month-end close'], $this->h())->assertStatus(422);

        $list = $this->actingAs($this->reader)->getJson('/api/v1/templates', $this->h())->assertOk()->json('data');
        $this->assertSame('blank-sop', $list[0]['id']);
        $mine = collect($list)->firstWhere('id', $t['id']);
        $this->assertSame(1, $mine['step_count']);

        $new = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => 'October close', 'template_id' => $t['id']], $this->h())->assertStatus(201)->json('data');
        $this->assertSame('Close the books.', $new['content']['purpose']);
        $steps = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$new['id']}/steps", $this->h())->json('data');
        $this->assertSame('Reconcile accounts', $steps[0]['instruction']);
        $this->assertTrue($steps[0]['is_critical']);

        // Another workspace cannot use or see it.
        [$other, $otherAdmin] = $this->makeWorkspace('other');
        $otherSpace = app(CurrentWorkspace::class)->runAs($other->id, fn () => Space::query()->firstOrFail()->id);
        $this->actingAs($otherAdmin)->postJson('/api/v1/documents', ['space_id' => $otherSpace, 'title' => 'X', 'template_id' => $t['id']], $this->wsHeaders($other))->assertStatus(422);
        $this->assertNull(collect($this->actingAs($otherAdmin)->getJson('/api/v1/templates', $this->wsHeaders($other))->json('data'))->firstWhere('id', $t['id']));

        // Only the creator or an admin deletes it.
        $this->actingAs($this->reader)->deleteJson("/api/v1/templates/{$t['id']}", [], $this->h())->assertStatus(403);
        $this->actingAs($this->admin)->deleteJson("/api/v1/templates/{$t['id']}", [], $this->h())->assertOk();
        $this->actingAs($this->admin)->deleteJson('/api/v1/templates/blank-sop', [], $this->h())->assertStatus(403);
    }

    public function test_internal_links_resolve_backlinks_follow_and_unavailable_targets_disclose_nothing(): void
    {
        $target = $this->doc('Refund policy');
        $source = $this->doc('Refund SOP');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$source}", ['content' => ['blocks' => [
            ['id' => 'l1', 'type' => 'link', 'document_id' => $target, 'text' => 'Refund policy'],
            ['id' => 'l2', 'type' => 'link', 'document_id' => str_repeat('Z', 26), 'text' => 'Gone'],
        ]]], $this->h())->assertOk();

        $resolved = $this->actingAs($this->editor)->postJson('/api/v1/document-links/resolve', ['ids' => [$target, str_repeat('Z', 26)]], $this->h())->assertOk()->json('data');
        $this->assertSame([$target], array_column($resolved, 'id'), 'a missing target is simply absent');

        $back = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$target}/backlinks", $this->h())->assertOk()->json('data');
        $this->assertSame([$source], array_column($back, 'id'));

        // The reader cannot see drafts: the target does not resolve for them, exactly like a deleted one.
        $this->assertSame([], $this->actingAs($this->reader)->postJson('/api/v1/document-links/resolve', ['ids' => [$target]], $this->h())->json('data'));

        // Removing the link block removes the backlink.
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$source}", ['content' => ['blocks' => []]], $this->h())->assertOk();
        $this->assertSame([], $this->actingAs($this->editor)->getJson("/api/v1/documents/{$target}/backlinks", $this->h())->json('data'));

        // Deleting a target leaves links broken, reported as unavailable.
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$source}", ['content' => ['blocks' => [['id' => 'l1', 'type' => 'link', 'document_id' => $target]]]], $this->h())->assertOk();
        $this->actingAs($this->editor)->deleteJson("/api/v1/documents/{$target}", [], $this->h())->assertOk();
        $this->assertSame([], $this->actingAs($this->editor)->postJson('/api/v1/document-links/resolve', ['ids' => [$target]], $this->h())->json('data'));
    }

    /**
     * Key order inside a JSON object carries no meaning, and MySQL's JSON type
     * normalises it; values, types and list order must still match exactly.
     */
    private static function canon(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $out = array_map(fn ($x) => self::canon($x), $v);
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
