<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

final class DocumentTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private function spaceId($ws): string
    {
        return app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);
    }

    public function test_create_from_template_seeds_sections_steps_and_body_text(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $res = $this->actingAs($admin)->postJson('/api/v1/documents', [
            'space_id' => $this->spaceId($ws), 'title' => 'Client onboarding', 'template_id' => 'client-delivery',
        ], $this->wsHeaders($ws));
        $res->assertStatus(201)->assertJsonPath('data.state', 'draft')->assertJsonPath('data.owner.id', $admin->id)->assertJsonCount(5, 'data.steps');
        $this->assertSame([1, 2, 3, 4, 5], array_column($res->json('data.steps'), 'position'));

        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($res): void {
            $doc = Document::query()->findOrFail($res->json('data.id'));
            $this->assertStringContainsString('kickoff call', $doc->body_text);
            $this->assertStringContainsString('Client onboarding', $doc->body_text);
        });

        $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $this->spaceId($ws), 'title' => 'x', 'template_id' => 'nope'], $this->wsHeaders($ws))->assertStatus(422);
    }

    public function test_autosave_merges_content_and_detects_concurrent_modification(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $id = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $this->spaceId($ws), 'title' => 'Refunds'], $this->wsHeaders($ws))->json('data.id');
        $doc = $this->actingAs($admin)->getJson("/api/v1/documents/{$id}", $this->wsHeaders($ws))->json('data');

        $r = $this->actingAs($admin)->patchJson("/api/v1/documents/{$id}", [
            'content' => ['purpose' => 'Refund customers within policy.', 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Hello']]],
            'expected_updated_at' => $doc['updated_at'],
        ], $this->wsHeaders($ws))->assertOk();
        $this->assertSame('Refund customers within policy.', $r->json('data.content.purpose'));
        $this->assertSame([], $r->json('data.content.prerequisites'));   // schema kept whole
        $this->assertSame(1, $r->json('data.content.version'));

        $this->travel(2)->seconds();
        // Stale expected_updated_at → 409 with the current state, never a silent overwrite.
        $this->actingAs($admin)->patchJson("/api/v1/documents/{$id}", ['title' => 'Stale'], $this->wsHeaders($ws))->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/documents/{$id}", ['title' => 'Overwrite', 'expected_updated_at' => $doc['updated_at']], $this->wsHeaders($ws))
            ->assertStatus(409)->assertJsonPath('error.code', 'conflict')->assertJsonPath('error.details.current.title', 'Stale');

        // Invalid block type is rejected.
        $this->actingAs($admin)->patchJson("/api/v1/documents/{$id}", ['content' => ['blocks' => [['id' => 'x', 'type' => 'html', 'text' => '<b>']]]], $this->wsHeaders($ws))->assertStatus(422);
    }

    public function test_editing_an_approved_document_creates_a_draft_revision_and_keeps_it_published(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $id = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $this->spaceId($ws), 'title' => 'Refunds', 'template_id' => 'onboarding'], $this->wsHeaders($ws))->json('data.id');

        // Simulate approval (Epic E): a version row and approved state.
        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($id, $admin): void {
            $doc = Document::query()->findOrFail($id);
            $v = DocumentVersion::create(['document_id' => $id, 'version_number' => 1, 'title' => 'Refunds v1', 'content' => $doc->content, 'authored_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => now()]);
            $doc->forceFill(['state' => 'approved', 'approved_version_id' => $v->id])->save();
        });

        $this->actingAs($admin)->patchJson("/api/v1/documents/{$id}", ['title' => 'Refunds (new draft)'], $this->wsHeaders($ws))->assertOk()->assertJsonPath('data.state', 'draft');
        $this->actingAs($admin)->getJson("/api/v1/documents/{$id}/published", $this->wsHeaders($ws))->assertOk()->assertJsonPath('data.title', 'Refunds v1')->assertJsonPath('data.version_number', 1);
    }

    public function test_readers_see_approved_documents_but_not_drafts_and_never_archived(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $sid = $this->spaceId($ws);
        $reader = $this->addMember($ws, 'r@example.test', 'reader');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => SpaceMember::create(['space_id' => $sid, 'user_id' => $reader->id, 'role' => 'reader']));

        $draft = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'title' => 'Draft'], $this->wsHeaders($ws))->json('data.id');
        $approved = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'title' => 'Live'], $this->wsHeaders($ws))->json('data.id');
        $archived = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'title' => 'Old'], $this->wsHeaders($ws))->json('data.id');
        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($approved, $archived): void {
            Document::query()->whereKey($approved)->update(['state' => 'approved']);
            Document::query()->whereKey($archived)->update(['state' => 'archived']);
        });

        $list = $this->actingAs($reader)->getJson('/api/v1/documents', $this->wsHeaders($ws))->assertOk()->json('data');
        $this->assertSame(['Live'], array_column($list, 'title'));
        $this->actingAs($reader)->getJson("/api/v1/documents/{$draft}", $this->wsHeaders($ws))->assertStatus(403);
        $this->actingAs($reader)->getJson("/api/v1/documents/{$archived}", $this->wsHeaders($ws))->assertStatus(403);
        $this->actingAs($reader)->patchJson("/api/v1/documents/{$approved}", ['title' => 'x'], $this->wsHeaders($ws))->assertStatus(403);
        $this->actingAs($reader)->postJson('/api/v1/documents', ['space_id' => $sid, 'title' => 'x'], $this->wsHeaders($ws))->assertStatus(403);

        // Search hits body text too.
        $this->actingAs($admin)->patchJson("/api/v1/documents/{$approved}", ['content' => ['purpose' => 'give someone their money back']], $this->wsHeaders($ws))->assertOk();
        $this->assertSame(['Live'], array_column($this->actingAs($admin)->getJson('/api/v1/documents?q=money', $this->wsHeaders($ws))->json('data'), 'title'));
    }

    public function test_steps_add_reorder_delete_keep_numbering_contiguous(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $id = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $this->spaceId($ws), 'title' => 'Steps'], $this->wsHeaders($ws))->json('data.id');
        $h = $this->wsHeaders($ws);

        $a = $this->actingAs($admin)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => 'A'], $h)->assertStatus(201)->json('data.id');
        $b = $this->actingAs($admin)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => 'B'], $h)->json('data.id');
        $c = $this->actingAs($admin)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => 'C', 'position' => 1], $h)->json('data.id');
        $names = fn () => array_column($this->actingAs($admin)->getJson("/api/v1/documents/{$id}/steps", $h)->json('data'), 'instruction');
        $this->assertSame(['C', 'A', 'B'], $names());

        $this->actingAs($admin)->postJson("/api/v1/documents/{$id}/steps/reorder", ['order' => [$b, $c, $a]], $h)->assertOk();
        $this->assertSame(['B', 'C', 'A'], $names());
        $this->actingAs($admin)->postJson("/api/v1/documents/{$id}/steps/reorder", ['order' => [$b, $c]], $h)->assertStatus(422);

        $this->actingAs($admin)->deleteJson("/api/v1/documents/{$id}/steps/{$c}", [], $h)->assertOk();
        $steps = $this->actingAs($admin)->getJson("/api/v1/documents/{$id}/steps", $h)->json('data');
        $this->assertSame([1, 2], array_column($steps, 'position'));
        $this->assertSame(['B', 'A'], array_column($steps, 'instruction'));

        $this->actingAs($admin)->patchJson("/api/v1/documents/{$id}/steps/{$a}", ['is_critical' => true, 'note' => 'Careful'], $h)->assertOk()->assertJsonPath('data.is_critical', true);
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => $this->assertStringContainsString('Careful', Document::query()->findOrFail($id)->body_text));
    }

    public function test_folder_delete_strategies_move_or_archive_documents(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $sid = $this->spaceId($ws);
        $h = $this->wsHeaders($ws);
        $keep = $this->actingAs($admin)->postJson('/api/v1/folders', ['space_id' => $sid, 'name' => 'Keep'], $h)->json('data.id');
        $gone = $this->actingAs($admin)->postJson('/api/v1/folders', ['space_id' => $sid, 'name' => 'Gone'], $h)->json('data.id');
        $d1 = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'folder_id' => $gone, 'title' => 'D1'], $h)->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/v1/folders/{$gone}?strategy=move&target_folder_id={$keep}", [], $h)->assertOk();
        $this->actingAs($admin)->getJson("/api/v1/documents/{$d1}", $h)->assertJsonPath('data.folder_id', $keep)->assertJsonPath('data.state', 'draft');

        $this->actingAs($admin)->deleteJson("/api/v1/folders/{$keep}?strategy=archive", [], $h)->assertOk();
        $this->actingAs($admin)->getJson("/api/v1/documents/{$d1}", $h)->assertJsonPath('data.folder_id', null)->assertJsonPath('data.state', 'archived');
    }
}
