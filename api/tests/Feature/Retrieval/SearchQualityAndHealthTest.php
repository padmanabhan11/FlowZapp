<?php

declare(strict_types=1);

namespace Tests\Feature\Retrieval;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\IndexUnhealthyNotification;
use App\Retrieval\FakeVectorStore;
use App\Retrieval\Stopwords;
use App\Retrieval\VectorStore;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/**
 * G4 filters inside the query, G3 keyword leg, G1-T5 index monitor, G2-T3/G3-T3 eval harness.
 *
 * On MySQL each test starts from a fresh schema instead of a rolled-back
 * transaction: InnoDB only adds rows to a FULLTEXT index when their
 * transaction commits, so the keyword leg cannot see rows written inside one.
 */
final class SearchQualityAndHealthTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase {
        refreshTestDatabase as private transactionalRefresh;
    }

    protected function refreshTestDatabase(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->transactionalRefresh();

            return;
        }
        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
        // Nothing rolls this test back, so leave an empty schema for the transactional tests that follow.
        $this->beforeApplicationDestroyed(fn () => $this->artisan('migrate:fresh'));
    }

    private Workspace $ws;

    private User $admin;

    private User $approver;

    private string $sid;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [$this->ws, $this->admin] = $this->makeWorkspace('acme', 'team');
        $this->sid = app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Space::query()->firstOrFail()->id);
        $this->approver = $this->addMember($this->ws, 'ap@example.test', 'approver');
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->approver->id, 'role' => 'approver']));
    }

    /** @return array<string,string> */
    private function h(): array
    {
        return $this->wsHeaders($this->ws);
    }

    private function publish(string $title, string $purpose, array $steps, string $type = 'sop', ?User $owner = null): string
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => $title, 'doc_type' => $type], $this->h())->json('data.id');
        $this->actingAs($this->admin)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => $purpose]] + ($owner ? ['owner_id' => $owner->id] : []), $this->h())->assertOk();
        foreach ($steps as $s) {
            $this->actingAs($this->admin)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => $s], $this->h())->assertStatus(201);
        }
        $this->actingAs($this->admin)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();

        return $id;
    }

    private function titles(array $body): array
    {
        return array_column($this->actingAs($this->admin)->postJson('/api/v1/search', $body, $this->h())->assertOk()->json('data.results'), 'title');
    }

    public function test_type_owner_and_approval_date_filter_inside_the_query(): void
    {
        // Twelve SOPs about invoices crowd the top of the ranking; the one policy must still be found when filtering to policies.
        for ($i = 1; $i <= 12; $i++) {
            $this->publish("Invoice procedure {$i}", 'How to send invoices to clients.', ["Send invoice batch {$i} to clients."]);
        }
        $policy = $this->publish('Invoice retention policy', 'Invoices are kept seven years.', ['Archive invoices after payment.'], 'policy', $this->approver);

        $this->assertSame(['Invoice retention policy'], $this->titles(['query' => 'send invoices to clients', 'limit' => 8, 'filters' => ['doc_type' => 'policy']]));
        $this->assertSame(['Invoice retention policy'], $this->titles(['query' => 'invoices', 'filters' => ['owner_id' => $this->approver->id]]));

        // Approval date: backdate the policy's approval by 100 days.
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => DocumentVersion::query()->where('document_id', $policy)->update(['approved_at' => now()->subDays(100)]));
        $recent = $this->titles(['query' => 'invoices', 'limit' => 20, 'filters' => ['approved_after' => now()->subDays(30)->toDateString()]]);
        $this->assertNotContains('Invoice retention policy', $recent);
        $this->assertCount(12, $recent);
        $this->assertSame(['Invoice retention policy'], $this->titles(['query' => 'invoices', 'filters' => ['approved_before' => now()->subDays(90)->toDateString()]]));

        $this->actingAs($this->admin)->postJson('/api/v1/search', ['query' => 'invoices', 'filters' => ['approved_after' => '2026-13-01']], $this->h())->assertStatus(422);
    }

    public function test_keyword_leg_ignores_stopwords_and_ranks_by_terms_matched(): void
    {
        $this->assertSame(['refund', 'order', 'not', 'after', '30', 'days'], Stopwords::terms('How do I refund an order not after 30 days?'));

        $this->publish('Vendor onboarding', 'Adding a new vendor.', ['Create the vendor record.']);
        $this->publish('Payroll cutoff', 'Monthly payroll.', ['Payroll cutoff is the 25th; late changes roll to next month.']);

        $store = app(VectorStore::class);
        $this->assertInstanceOf(FakeVectorStore::class, $store);
        $store->points = [];   // vector leg empty: whatever comes back is the keyword leg alone
        $this->assertSame('Payroll cutoff', $this->titles(['query' => 'what is the payroll cutoff', 'instant' => false])[0] ?? null);
    }

    public function test_monitor_requeues_unindexed_documents_alerts_admins_and_clears_stale_chunks(): void
    {
        $id = $this->publish('Refund Policy', 'How we give customers their money back.', ['Check the order date.']);
        $archived = $this->publish('Old Travel Policy', 'Superseded.', ['Book economy.']);

        app(CurrentWorkspace::class)->runAs($this->ws->id, function () use ($id, $archived): void {
            DocumentChunk::query()->where('document_id', $id)->delete();              // simulate a failed index job
            DocumentVersion::query()->where('document_id', $id)->update(['approved_at' => now()->subHours(2)]);
            Document::query()->whereKey($archived)->update(['state' => 'archived']);   // archived without de-indexing (simulated drift)
        });

        $this->actingAs($this->admin)->getJson('/api/v1/analytics/overview', $this->h())->assertOk()->assertJsonPath('data.unindexed.0.document_id', $id);

        $this->artisan('retrieval:check-index')->expectsOutputToContain('1 unindexed approved (1 alerted), 1 stale removed')->assertSuccessful();

        app(CurrentWorkspace::class)->runAs($this->ws->id, function () use ($id, $archived): void {
            $this->assertGreaterThan(0, DocumentChunk::query()->where('document_id', $id)->whereNotNull('indexed_at')->count(), 're-queued and re-indexed (sync queue)');
            $this->assertSame(0, DocumentChunk::query()->where('document_id', $archived)->count());
        });
        Notification::assertSentTo($this->admin, IndexUnhealthyNotification::class, fn ($n) => $n->documents[0]['document_id'] === $id);
        $this->assertSame([], $this->actingAs($this->admin)->getJson('/api/v1/analytics/overview', $this->h())->json('data.unindexed'));

        // A second run the same day finds nothing and does not re-alert.
        $this->artisan('retrieval:check-index')->expectsOutputToContain('0 unindexed approved (0 alerted), 0 stale removed')->assertSuccessful();
        Notification::assertSentToTimes($this->admin, IndexUnhealthyNotification::class, 1);
    }

    public function test_a_document_under_revision_stays_indexed_and_is_not_flagged(): void
    {
        $id = $this->publish('Refund Policy', 'How we give customers their money back.', ['Check the order date.']);
        $this->actingAs($this->admin)->patchJson("/api/v1/documents/{$id}", ['title' => 'Refund Policy (draft)'], $this->h())->assertOk()->assertJsonPath('data.state', 'draft');

        $this->artisan('retrieval:check-index')->expectsOutputToContain('0 unindexed approved (0 alerted), 0 stale removed')->assertSuccessful();
        $this->assertSame(['Refund Policy'], $this->titles(['query' => 'refund money back']));
    }

    public function test_retrieval_eval_scores_a_query_set_under_several_fusion_settings(): void
    {
        $this->publish('Refund Policy', 'How we give customers their money back.', ['Check the order date.', 'Refunds after 30 days need ops-lead approval.']);
        $this->publish('Client Onboarding', 'Bring a new client in.', ['Create the client folder.', 'Send the welcome email.']);
        $set = tempnam(sys_get_temp_dir(), 'set').'.json';
        file_put_contents($set, (string) json_encode(['queries' => [
            ['q' => 'refund after 30 days approval', 'expect' => ['Refund Policy'], 'section' => 'step:2'],
            ['q' => 'welcome email for a new client', 'expect' => ['Client Onboarding']],
            ['q' => 'what is the parental leave allowance', 'unanswerable' => true],
        ]]));
        $out = sys_get_temp_dir().'/reval-'.uniqid();

        $this->artisan('retrieval:eval', ['set' => $set, '--workspace' => 'acme', '--as' => $this->admin->email, '--weights' => '1:0.6,0:1', '--out' => $out])->assertSuccessful();

        $md = (string) file_get_contents("{$out}/summary.md");
        $this->assertStringContainsString('| Correct document in top 3 | > 90% | 100% | 100% |', $md);
        $this->assertStringContainsString('| Unanswerable queries | 15 | 1 | 1 |', $md);
        $csv = array_map('str_getcsv', file("{$out}/queries.csv", FILE_IGNORE_NEW_LINES) ?: []);
        $this->assertSame(['setting', 'kind', 'query', 'rank', 'section_hit5', 'search_refused', 'top_score', 'assistant_refused', 'top'], $csv[0]);
        $this->assertCount(1 + 3 * 2, $csv);
    }
}
