<?php

declare(strict_types=1);

namespace Tests\Feature\Retrieval;

use App\Ai\FakeLlm;
use App\Models\DocumentChunk;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Retrieval\FakeVectorStore;
use App\Retrieval\VectorStore;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** M3 end to end with fakes: approve → chunks + vectors; search/chat filtered in-query by permission; refusal; citations; gaps. */
final class RetrievalTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private Workspace $ws;

    private User $admin;

    private User $approver;

    private string $sid;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [$this->ws, $this->admin] = $this->makeWorkspace('acme');
        $this->sid = app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Space::query()->firstOrFail()->id);
        $this->approver = $this->addMember($this->ws, 'ap@example.test', 'approver');
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->approver->id, 'role' => 'approver']));
        FakeLlm::$responses['[answer]'] = json_encode(['answer' => 'Refunds after 30 days need ops-lead approval [1].', 'citations' => [1], 'refused' => false]);
    }

    private function h(): array
    {
        return $this->wsHeaders($this->ws);
    }

    /** Create, fill and approve a document; returns its id. */
    private function publish(string $title, string $purpose, array $steps, ?string $folderId = null, ?string $spaceId = null): string
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/documents', ['space_id' => $spaceId ?? $this->sid, 'folder_id' => $folderId, 'title' => $title], $this->h())->json('data.id');
        $this->actingAs($this->admin)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => $purpose]], $this->h())->assertOk();
        foreach ($steps as $s) {
            $this->actingAs($this->admin)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => $s], $this->h())->assertStatus(201);
        }
        $this->actingAs($this->admin)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();

        return $id;
    }

    public function test_approval_indexes_the_document_and_search_finds_it_by_meaning_with_a_cited_instant_answer(): void
    {
        $refunds = $this->publish('Refund Policy', 'How we give customers their money back.', ['Check the order date.', 'Refunds after 30 days need ops-lead approval.', 'Issue the refund from the payments dashboard.']);
        $this->publish('Client Onboarding', 'Bring a new client in.', ['Create the client folder.', 'Send the welcome email.']);

        app(CurrentWorkspace::class)->runAs($this->ws->id, function () use ($refunds): void {
            $chunks = DocumentChunk::query()->where('document_id', $refunds)->get();
            $this->assertSame(4, $chunks->count());                       // purpose + 3 steps
            $this->assertTrue($chunks->every(fn ($c) => $c->indexed_at !== null && $c->embed_model === 'fake-hash-64'));
            /** @var FakeVectorStore $store */
            $store = app(VectorStore::class);
            $this->assertCount(4 + 3, $store->points[$this->ws->id]);
        });

        $r = $this->actingAs($this->admin)->postJson('/api/v1/search', ['query' => 'give someone their money back refund'], $this->h())->assertOk();
        $this->assertSame('Refund Policy', $r->json('data.results.0.title'));
        $this->assertNotNull($r->json('data.instant_answer'));
        $this->assertSame($refunds, $r->json('data.instant_answer.citations.0.document_id'));
        $this->assertStringStartsWith('step:', $r->json('data.instant_answer.citations.0.section_ref') ?? $r->json('data.instant_answer.citations.0.section_ref'));
    }

    public function test_chat_answers_with_citations_refuses_without_source_and_never_sees_other_spaces(): void
    {
        $this->publish('Refund Policy', 'How we give customers their money back.', ['Refunds after 30 days need ops-lead approval.']);
        $finance = app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Space::create(['name' => 'Finance', 'created_by' => $this->admin->id])->id);
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => SpaceMember::create(['space_id' => $finance, 'user_id' => $this->approver->id, 'role' => 'approver']));
        $this->publish('Board Compensation', 'Equity for directors.', ['Directors receive equity grants annually.'], null, $finance);

        $reader = $this->addMember($this->ws, 'r@example.test', 'reader');
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => SpaceMember::create(['space_id' => $this->sid, 'user_id' => $reader->id, 'role' => 'reader']));

        $sess = $this->actingAs($reader)->postJson('/api/v1/chat/sessions', [], $this->h())->assertStatus(201)->json('data.id');
        $m = $this->actingAs($reader)->postJson("/api/v1/chat/sessions/{$sess}/messages", ['content' => 'How do we handle a refund past 30 days?'], $this->h())->assertOk()->json('data');
        $this->assertFalse($m['refused']);
        $this->assertNotEmpty($m['citations']);
        $this->assertSame('Refund Policy', $m['citations'][0]['title']);

        // A question only answerable from a space the reader cannot see: the model is told nothing about it.
        FakeLlm::$responses['[answer]'] = json_encode(['answer' => 'I cannot find this.', 'citations' => [], 'refused' => true]);
        $m2 = $this->actingAs($reader)->postJson("/api/v1/chat/sessions/{$sess}/messages", ['content' => 'What equity do directors receive?'], $this->h())->assertOk()->json('data');
        $this->assertTrue($m2['refused']);
        $this->assertSame([], $m2['citations']);
        $this->assertStringContainsString('No approved document', $m2['content']);

        // An "answer" with zero citations is treated as a refusal (BRL-05).
        FakeLlm::$responses['[answer]'] = json_encode(['answer' => 'Directors get 1% each.', 'citations' => [], 'refused' => false]);
        $m3 = $this->actingAs($reader)->postJson("/api/v1/chat/sessions/{$sess}/messages", ['content' => 'director equity?'], $this->h())->json('data');
        $this->assertTrue($m3['refused']);

        // Rating, history, and the knowledge-gaps report for admins.
        $this->actingAs($reader)->postJson("/api/v1/chat/messages/{$m['id']}/rating", ['helpful' => true], $this->h())->assertOk()->assertJsonPath('data.rated_helpful', true);
        $this->assertCount(6, $this->actingAs($reader)->getJson("/api/v1/chat/sessions/{$sess}/messages", $this->h())->json('data'));
        $this->actingAs($reader)->getJson('/api/v1/analytics/knowledge-gaps', $this->h())->assertStatus(403);
        $gaps = $this->actingAs($this->admin)->getJson('/api/v1/analytics/knowledge-gaps', $this->h())->assertOk()->json('data');
        $this->assertCount(2, $gaps);

        // SSE variant streams retrieval → tokens → done.
        FakeLlm::$responses['[answer]'] = json_encode(['answer' => 'Refunds after 30 days need ops-lead approval [1].', 'citations' => [1], 'refused' => false]);
        $sse = $this->actingAs($reader)->withHeaders($this->h() + ['Accept' => 'text/event-stream'])->post("/api/v1/chat/sessions/{$sess}/messages", ['content' => 'refund after 30 days?']);
        $sse->assertOk();
        $body = $sse->streamedContent();
        $this->assertStringContainsString('event: retrieval', $body);
        $this->assertStringContainsString('event: token', $body);
        $this->assertStringContainsString('event: done', $body);
    }

    public function test_archive_and_permission_removal_take_effect_on_retrieval_immediately(): void
    {
        $id = $this->publish('Refund Policy', 'Money back.', ['Refunds after 30 days need ops-lead approval.']);
        $reader = $this->addMember($this->ws, 'r@example.test', 'reader');
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => SpaceMember::create(['space_id' => $this->sid, 'user_id' => $reader->id, 'role' => 'reader']));
        $this->assertCount(1, $this->actingAs($reader)->postJson('/api/v1/search', ['query' => 'refund'], $this->h())->json('data.results'));

        $this->actingAs($this->admin)->deleteJson("/api/v1/spaces/{$this->sid}/members/{$reader->id}", [], $this->h())->assertOk();
        $this->assertCount(0, $this->actingAs($reader)->postJson('/api/v1/search', ['query' => 'refund'], $this->h())->json('data.results'));

        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/archive", [], $this->h())->assertOk();
        $this->assertCount(0, $this->actingAs($this->admin)->postJson('/api/v1/search', ['query' => 'refund'], $this->h())->json('data.results'));
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => $this->assertSame(0, DocumentChunk::query()->where('document_id', $id)->count()));
    }
}
