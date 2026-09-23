<?php

declare(strict_types=1);

namespace Tests\Feature\Retrieval;

use App\Ai\FakeLlm;
use App\Ai\LlmDriver;
use App\Models\Document;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/**
 * G5-T3 — adversarial retrieval leak tests (08 "Adversarial retrieval test";
 * non-negotiables 3 and 4). A reader tries every route by which a document
 * they cannot see might show itself: naming it in search and chat, filtering
 * on it, probing its id, following links to it, catching its draft or
 * archived text, and crossing workspaces. Each probe must behave exactly as
 * it would for a document that does not exist, and nothing hidden may reach
 * the model's prompt.
 */
final class AdversarialRetrievalTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private Workspace $ws;

    private User $admin;

    private User $approver;

    private User $reader;

    private string $general;

    private string $finance;

    /** Every prompt sent to the model during the test. */
    private FakeLlm $llm;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [$this->ws, $this->admin] = $this->makeWorkspace('acme', 'team');
        $cw = app(CurrentWorkspace::class);
        $this->general = $cw->runAs($this->ws->id, fn () => Space::query()->firstOrFail()->id);
        $this->finance = $cw->runAs($this->ws->id, fn () => Space::create(['name' => 'Finance', 'created_by' => $this->admin->id])->id);
        $this->approver = $this->addMember($this->ws, 'ap@example.test', 'approver');
        $this->reader = $this->addMember($this->ws, 'reader@example.test', 'reader');
        $cw->runAs($this->ws->id, function (): void {
            foreach ([$this->general, $this->finance] as $sid) {
                SpaceMember::create(['space_id' => $sid, 'user_id' => $this->approver->id, 'role' => 'approver']);
            }
            SpaceMember::create(['space_id' => $this->general, 'user_id' => $this->reader->id, 'role' => 'reader']);   // not Finance
        });

        $this->llm = new class extends FakeLlm
        {
            /** @var list<string> */
            public array $prompts = [];

            public function complete(string $system, string $user, int $maxTokens = 4096, float $temperature = 0.0): array
            {
                $this->prompts[] = $user;

                return parent::complete($system, $user, $maxTokens, $temperature);
            }
        };
        $this->app->instance(LlmDriver::class, $this->llm);
        FakeLlm::$responses = ['[answer]' => (string) json_encode(['answer' => 'See the procedure [1].', 'citations' => [1], 'refused' => false])];
    }

    /** @return array<string,string> */
    private function h(?Workspace $ws = null): array
    {
        return $this->wsHeaders($ws ?? $this->ws);
    }

    private function publish(string $title, string $purpose, array $steps, string $spaceId, ?string $folderId = null): string
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/documents', ['space_id' => $spaceId, 'folder_id' => $folderId, 'title' => $title], $this->h())->json('data.id');
        $this->actingAs($this->admin)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => $purpose]], $this->h())->assertOk();
        foreach ($steps as $s) {
            $this->actingAs($this->admin)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => $s], $this->h())->assertStatus(201);
        }
        $this->actingAs($this->admin)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();

        return $id;
    }

    private function search(string $q, array $extra = []): array
    {
        return $this->actingAs($this->reader)->postJson('/api/v1/search', ['query' => $q] + $extra, $this->h())->assertOk()->json('data');
    }

    private function assertNoPromptMentions(string ...$needles): void
    {
        foreach ($this->llm->prompts as $p) {
            // Only the retrieved sources count: the reader's own question naturally repeats their words.
            $sources = str_contains($p, 'Sources:') ? substr($p, (int) strpos($p, 'Sources:')) : $p;
            foreach ($needles as $n) {
                $this->assertStringNotContainsStringIgnoringCase($n, $sources, 'hidden content reached the model prompt');
            }
        }
    }

    private function hiddenBoardDoc(): string
    {
        return $this->publish('Board Compensation', 'How directors are paid.', ['Directors receive quarterly equity grants of 0.25 percent.'], $this->finance);
    }

    public function test_naming_a_hidden_document_in_search_looks_exactly_like_naming_a_missing_one(): void
    {
        $this->publish('Refund Policy', 'How we give customers their money back.', ['Refunds after 30 days need ops-lead approval.'], $this->general);
        $probe = 'Board Compensation director equity grants';

        // The reader's view before the hidden document exists and after must be byte-for-byte the same.
        $before = $this->search($probe);
        $this->hiddenBoardDoc();
        $after = $this->search($probe);
        $this->assertSame($before, $after, 'a hidden document must be indistinguishable from none');
        $this->assertNotContains('Board Compensation', array_column($after['results'], 'title'));

        // Filters cannot widen scope: the hidden space or a made-up space id both give nothing.
        $this->assertSame([], $this->search('equity grants', ['space_ids' => [$this->finance]])['results']);
        $this->assertSame([], $this->search('equity grants', ['space_ids' => [Str::ulid()->toBase32()]])['results']);
        $this->assertNotContains('Board Compensation', array_column($this->search('equity grants', ['filters' => ['owner_id' => $this->admin->id]])['results'], 'title'));
        $this->assertNoPromptMentions('equity', 'Board Compensation');
    }

    public function test_chat_refuses_identically_and_never_sees_the_hidden_text(): void
    {
        $this->publish('Refund Policy', 'How we give customers their money back.', ['Refunds after 30 days need ops-lead approval.'], $this->general);
        $this->hiddenBoardDoc();
        $sess = $this->actingAs($this->reader)->postJson('/api/v1/chat/sessions', [], $this->h())->assertStatus(201)->json('data.id');

        $hidden = $this->actingAs($this->reader)->postJson("/api/v1/chat/sessions/{$sess}/messages", ['content' => 'What equity grants do directors receive per Board Compensation?'], $this->h())->assertOk()->json('data');
        $missing = $this->actingAs($this->reader)->postJson("/api/v1/chat/sessions/{$sess}/messages", ['content' => 'What is the galactic plutonium allowance?'], $this->h())->assertOk()->json('data');

        $this->assertTrue($hidden['refused']);
        $this->assertSame($missing['refused'], $hidden['refused']);
        $this->assertSame($missing['content'], $hidden['content']);
        $this->assertSame([], $hidden['citations']);
        $this->assertNoPromptMentions('equity', 'Board Compensation', 'directors');

        // A chat scoped to the hidden document is refused exactly like one scoped to a made-up id.
        $scopedHidden = $this->actingAs($this->reader)->postJson('/api/v1/chat/sessions', ['scope_document_id' => $this->hiddenId()], $this->h());
        $scopedMissing = $this->actingAs($this->reader)->postJson('/api/v1/chat/sessions', ['scope_document_id' => Str::ulid()->toBase32()], $this->h());
        $this->assertSame($scopedMissing->status(), $scopedHidden->status());
        $this->assertSame($scopedMissing->json('error.code'), $scopedHidden->json('error.code'));
    }

    private function hiddenId(): string
    {
        return app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Document::query()->where('title', 'Board Compensation')->value('id'));
    }

    public function test_probing_a_hidden_document_id_answers_like_a_missing_id_on_every_route(): void
    {
        $hidden = $this->hiddenBoardDoc();
        $missing = Str::ulid()->toBase32();

        foreach (['', '/published', '/backlinks', '/steps', '/versions'] as $suffix) {
            $a = $this->actingAs($this->reader)->getJson("/api/v1/documents/{$hidden}{$suffix}", $this->h());
            $b = $this->actingAs($this->reader)->getJson("/api/v1/documents/{$missing}{$suffix}", $this->h());
            $this->assertSame($b->status(), $a->status(), "GET /documents/{id}{$suffix}: status reveals existence");
            $this->assertSame($b->json('error.code'), $a->json('error.code'), "GET /documents/{id}{$suffix}: error code reveals existence");
            $this->assertStringNotContainsString('Board', (string) $a->getContent());
        }

        // Link resolution silently drops both.
        $r = $this->actingAs($this->reader)->postJson('/api/v1/document-links/resolve', ['ids' => [$hidden, $missing]], $this->h())->assertOk();
        $this->assertSame([], $r->json('data'));
    }

    public function test_a_hidden_document_linking_to_a_visible_one_does_not_appear_in_its_backlinks(): void
    {
        $visible = $this->publish('Refund Policy', 'How we give customers their money back.', ['Check the order date.'], $this->general);
        $hidden = $this->hiddenBoardDoc();
        $this->actingAs($this->admin)->patchJson("/api/v1/documents/{$hidden}", ['content' => ['blocks' => [['id' => 'l1', 'type' => 'link', 'text' => 'Refunds', 'document_id' => $visible]]]], $this->h())->assertOk();

        $this->assertCount(1, $this->actingAs($this->admin)->getJson("/api/v1/documents/{$visible}/backlinks", $this->h())->json('data'));
        $this->assertSame([], $this->actingAs($this->reader)->getJson("/api/v1/documents/{$visible}/backlinks", $this->h())->assertOk()->json('data'));
    }

    public function test_a_denied_folder_hides_its_documents_inside_a_readable_space(): void
    {
        $folder = $this->actingAs($this->admin)->postJson('/api/v1/folders', ['space_id' => $this->general, 'name' => 'Salaries'], $this->h())->assertStatus(201)->json('data.id');
        $this->actingAs($this->admin)->putJson("/api/v1/folders/{$folder}/permissions/{$this->reader->id}", ['role' => 'none'], $this->h())->assertOk();
        $this->publish('Refund Policy', 'How we give customers their money back.', ['Check the order date.'], $this->general);
        $probe = 'salary bands level five engineers band seventeen';

        $before = $this->search($probe);
        $this->publish('Salary Bands', 'Pay ranges by level.', ['Level five engineers earn within band seventeen.'], $this->general, $folder);
        $this->assertSame($before, $this->search($probe), 'a denied folder must be indistinguishable from an empty one');
        $this->assertSame('Salary Bands', $this->actingAs($this->admin)->postJson('/api/v1/search', ['query' => $probe], $this->h())->json('data.results.0.title'));
        $this->llm->prompts = [];   // the admin's own search above legitimately sent the salary text to the model

        $sess = $this->actingAs($this->reader)->postJson('/api/v1/chat/sessions', [], $this->h())->json('data.id');
        $this->actingAs($this->reader)->postJson("/api/v1/chat/sessions/{$sess}/messages", ['content' => 'What band are level five engineers in?'], $this->h())->assertOk();
        $this->search($probe);
        $this->assertNoPromptMentions('band seventeen', 'Salary Bands');
    }

    public function test_draft_edits_and_archived_documents_never_surface(): void
    {
        $id = $this->publish('Expense Claims', 'How to claim expenses.', ['Submit receipts within 14 days.'], $this->general);

        // A draft revision on top of the approved version (title and purpose changed): the draft's words and
        // title never surface; the approved version stays live in search under its approved title (non-negotiable 5).
        $this->actingAs($this->admin)->patchJson("/api/v1/documents/{$id}", ['title' => 'Zanzibar Card Programme', 'content' => ['purpose' => 'Unreleased: corporate cards arrive in zanzibar quarter.']], $this->h())->assertOk()->assertJsonPath('data.state', 'draft');
        foreach ($this->search('corporate cards zanzibar quarter')['results'] as $r) {
            $this->assertStringNotContainsStringIgnoringCase('zanzibar', $r['title'].' '.$r['snippet']);
        }
        $live = $this->search('claim expenses receipts')['results'][0] ?? null;
        $this->assertSame('Expense Claims', $live['title'] ?? null, 'the approved version stays searchable while a revision is drafted');
        $this->assertSame('approved', $live['state']);

        // Archive: gone from search and from the assistant in the same request.
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/archive", [], $this->h())->assertOk();
        $this->assertNotContains($id, array_column($this->search('claim expenses receipts')['results'], 'document_id'));
        $this->assertNoPromptMentions('zanzibar');
    }

    public function test_another_workspace_can_neither_reach_nor_retrieve_this_one(): void
    {
        $this->publish('Refund Policy', 'How we give customers their money back.', ['Refunds after 30 days need ops-lead approval.'], $this->general);
        [$other, $outsider] = $this->makeWorkspace('globex', 'team');

        // Naming this workspace in the header: 403, never 404 and never data.
        $this->actingAs($outsider)->postJson('/api/v1/search', ['query' => 'refund'], $this->h($this->ws))->assertStatus(403);
        // Searching from their own workspace finds nothing of ours.
        $this->assertSame([], $this->actingAs($outsider)->postJson('/api/v1/search', ['query' => 'refunds after 30 days ops-lead approval'], $this->h($other))->assertOk()->json('data.results'));
        // Our reader naming a workspace that does not exist gets the same 403 as naming one that does.
        $a = $this->actingAs($this->reader)->postJson('/api/v1/search', ['query' => 'refund'], $this->h($other));
        $b = $this->actingAs($this->reader)->postJson('/api/v1/search', ['query' => 'refund'], ['X-Workspace-Id' => Str::ulid()->toBase32(), 'Accept' => 'application/json']);
        $this->assertSame(403, $a->status());
        $this->assertSame($a->status(), $b->status());
        $this->assertSame($a->json('error.code'), $b->json('error.code'));
    }
}
