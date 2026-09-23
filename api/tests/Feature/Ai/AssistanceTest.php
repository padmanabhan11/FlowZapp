<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\FakeLlm;
use App\Models\Document;
use App\Models\DocumentStep;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** FR-801..805: proposals never mutate; translation is a linked draft; a re-approved source marks it stale. */
final class AssistanceTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private Workspace $ws;

    private User $admin;

    private User $editor;

    private User $approver;

    private string $sid;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [$this->ws, $this->admin] = $this->makeWorkspace('acme');
        $this->sid = app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Space::query()->firstOrFail()->id);
        $this->editor = $this->addMember($this->ws, 'ed@example.test', 'editor');
        $this->approver = $this->addMember($this->ws, 'ap@example.test', 'approver');
        app(CurrentWorkspace::class)->runAs($this->ws->id, function (): void {
            SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->editor->id, 'role' => 'editor']);
            SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->approver->id, 'role' => 'approver']);
        });
    }

    private function h(): array
    {
        return $this->wsHeaders($this->ws);
    }

    public function test_rewrite_is_a_proposal_and_the_document_is_untouched_until_the_client_patches(): void
    {
        $id = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => 'Refunds', 'template_id' => 'onboarding'], $this->h())->json('data.id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'so basically this is kind of about doing refunds i guess']], $this->h())->assertOk();
        $before = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}", $this->h())->json('data.updated_at');

        FakeLlm::$responses = ['[rewrite]' => json_encode(['section:purpose' => 'Process refunds consistently.', 'invented:key' => 'x'])];
        $r = $this->actingAs($this->editor)->postJson('/api/v1/ai/rewrite', ['document_id' => $id, 'scope' => 'document'], $this->h())->assertOk();
        $this->assertSame('Process refunds consistently.', $r->json('data.proposed.section:purpose'));
        $this->assertArrayNotHasKey('invented:key', $r->json('data.proposed'));
        $this->assertContains('section:purpose', $r->json('data.changed'));
        $this->assertArrayHasKey('step:', collect($r->json('data.original'))->keys()->mapWithKeys(fn ($k) => [substr($k, 0, 5) => 1])->all(), 'steps are included in document scope');

        // Nothing changed server-side (FR-805).
        $after = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}", $this->h())->json('data');
        $this->assertSame($before, $after['updated_at']);
        $this->assertStringContainsString('basically', $after['content']['purpose']);

        // Readers cannot ask for rewrites; selection scope works on free text.
        $reader = $this->addMember($this->ws, 'rd@example.test', 'reader');
        $this->actingAs($reader)->postJson('/api/v1/ai/rewrite', ['document_id' => $id, 'scope' => 'document'], $this->h())->assertStatus(403);
        FakeLlm::$responses = ['[rewrite]' => json_encode(['selection' => 'Click Save.'])];
        $this->actingAs($this->editor)->postJson('/api/v1/ai/rewrite', ['document_id' => $id, 'scope' => 'selection', 'text' => 'you should probably click on save now'], $this->h())
            ->assertOk()->assertJsonPath('data.proposed.selection', 'Click Save.');

        FakeLlm::$responses = ['[title]' => json_encode(['titles' => ['Issue a refund', 'Refund an order', '']])];
        $this->assertSame(['Issue a refund', 'Refund an order'], $this->actingAs($this->editor)->postJson('/api/v1/ai/suggest-title', ['document_id' => $id], $this->h())->assertOk()->json('data.titles'));
    }

    public function test_translation_creates_a_linked_draft_and_goes_stale_when_the_source_is_re_approved(): void
    {
        $id = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => 'Refunds', 'template_id' => 'onboarding'], $this->h())->json('data.id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Handle refunds.']], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();

        FakeLlm::$responses = ['[translate]' => json_encode(['title' => 'Erstattungen', 'section:purpose' => 'Erstattungen bearbeiten.'])];
        $this->actingAs($this->editor)->postJson('/api/v1/ai/translate', ['document_id' => $id, 'target_language' => 'en'], $this->h())->assertStatus(422);
        $r = $this->actingAs($this->editor)->postJson('/api/v1/ai/translate', ['document_id' => $id, 'target_language' => 'de'], $this->h())->assertStatus(201);
        $de = $r->json('data.id');
        $this->assertSame('draft', $r->json('data.state'));
        $this->assertSame($id, $r->json('data.translation_of'));
        $copy = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$de}", $this->h())->assertOk()->json('data');
        $this->assertSame('Erstattungen', $copy['title']);
        $this->assertSame('Erstattungen bearbeiten.', $copy['content']['purpose']);
        $this->assertSame('de', $copy['language']);
        $this->assertCount(5, $this->actingAs($this->editor)->getJson("/api/v1/documents/{$de}/steps", $this->h())->json('data'), 'steps are copied (untranslated when the model omits them)');
        $this->actingAs($this->editor)->postJson('/api/v1/ai/translate', ['document_id' => $id, 'target_language' => 'de'], $this->h())->assertStatus(409);

        // Source stays approved and untouched; the translation is not stale yet.
        $src = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}", $this->h())->json('data');
        $this->assertSame('approved', $src['state']);
        $this->assertFalse($copy['translation_stale']);

        // I2-T3: the link reads both ways — and readers only see translations that are published.
        $this->assertSame([['id' => $de, 'language' => 'de', 'title' => 'Erstattungen', 'state' => 'draft', 'translation_stale' => false]], $src['translations']);
        $this->assertNull($src['source']);
        $this->assertSame(['id' => $id, 'language' => 'en', 'title' => 'Refunds', 'state' => 'approved', 'translation_stale' => false], $copy['source']);
        $this->assertSame([], $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/published", $this->h())->json('data.translations'), 'a draft translation is not offered to readers');

        // Approve the translation, then re-approve the source → translation marked stale (FR-804), visible to readers.
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$de}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$de}/approve", [], $this->h())->assertOk();
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Handle refunds fairly.']], $this->h())->assertOk();
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => DocumentStep::query()->where('document_id', $id)->update(['verified_at' => now()]));
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();
        $pub = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$de}/published", $this->h())->assertOk()->json('data');
        $this->assertTrue($pub['translation_stale']);
        $this->assertNotNull($pub['translation_stale_since']);
        $this->assertSame('Refunds', $pub['source']['title']);
        $srcPub = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/published", $this->h())->json('data');
        $this->assertSame([['id' => $de, 'language' => 'de', 'title' => 'Erstattungen', 'state' => 'approved', 'translation_stale' => true]], $srcPub['translations'], 'once approved, the translation is offered from the source, flagged stale');
        $this->assertTrue(app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Document::query()->findOrFail($de)->translation_stale));
    }
}
