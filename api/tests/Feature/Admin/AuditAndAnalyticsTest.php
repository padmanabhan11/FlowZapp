<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditEntry;
use App\Models\Document;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** Epic L: S21 audit log (FR-414, FR-912), S20 analytics (FR-908, FR-909). */
final class AuditAndAnalyticsTest extends TestCase
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
        [$this->ws, $this->admin] = $this->makeWorkspace('acme', 'team');
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

    private function approved(string $title): string
    {
        $id = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => $title, 'template_id' => 'onboarding'], $this->h())->json('data.id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Do.']], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();

        return $id;
    }

    public function test_audit_log_is_filterable_exportable_and_records_the_export(): void
    {
        $id = $this->approved('Refunds');
        $this->actingAs($this->admin)->patchJson("/api/v1/workspaces/{$this->ws->id}/members/{$this->editor->id}", ['role' => 'approver'], $this->h())->assertOk();

        $this->actingAs($this->editor)->getJson('/api/v1/audit-log', $this->h())->assertStatus(403);

        $all = $this->actingAs($this->admin)->getJson('/api/v1/audit-log', $this->h())->assertOk();
        $actions = collect($all->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('document.approved'));
        $this->assertTrue($actions->contains('member.role_changed'));
        $approvedRow = collect($all->json('data'))->firstWhere('action', 'document.approved');
        $this->assertSame($this->approver->id, $approvedRow['actor']['id']);
        $this->assertSame($id, $approvedRow['entity_id']);
        $this->assertNotNull($approvedRow['at']);

        $only = $this->actingAs($this->admin)->getJson('/api/v1/audit-log?action=document.approved', $this->h())->assertOk()->json('data');
        $this->assertCount(1, $only);
        $byActor = $this->actingAs($this->admin)->getJson("/api/v1/audit-log?actor_id={$this->admin->id}", $this->h())->json('data');
        $this->assertTrue(collect($byActor)->every(fn ($r) => $r['actor']['id'] === $this->admin->id));
        $this->assertSame([], $this->actingAs($this->admin)->getJson('/api/v1/audit-log?to=2000-01-01', $this->h())->json('data'));

        // Pagination by cursor.
        $page = $this->actingAs($this->admin)->getJson('/api/v1/audit-log?limit=2', $this->h())->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertNotNull($page->json('next_cursor'));
        $next = $this->actingAs($this->admin)->getJson('/api/v1/audit-log?limit=2&cursor='.$page->json('next_cursor'), $this->h())->json('data');
        $this->assertLessThan($page->json('data.1.id'), $next[0]['id']);

        // Export respects the filter and is itself recorded.
        $csv = $this->actingAs($this->admin)->get('/api/v1/audit-log/export?action=document.approved', $this->h())->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('document.approved', $lines[1]);
        $this->assertTrue(app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => AuditEntry::query()->where('action', 'audit_log.exported')->exists()));

        // No mutation route exists.
        $this->actingAs($this->admin)->deleteJson('/api/v1/audit-log/1', [], $this->h())->assertStatus(404);
        $this->actingAs($this->admin)->patchJson('/api/v1/audit-log/1', [], $this->h())->assertStatus(404);
    }

    public function test_analytics_overview_reports_state_counts_overdue_reviews_reads_and_chat(): void
    {
        $a = $this->approved('Alpha');
        $this->approved('Beta');
        $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => 'Draft only'], $this->h())->assertStatus(201);
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Document::query()->whereKey($a)->update(['review_due_at' => now()->subDay()]));

        // Reads: two people read Alpha, one reads it twice the same day (counts once).
        $this->actingAs($this->editor)->getJson("/api/v1/documents/{$a}/published", $this->h())->assertOk();
        $this->actingAs($this->editor)->getJson("/api/v1/documents/{$a}/published", $this->h())->assertOk();
        $this->actingAs($this->approver)->getJson("/api/v1/documents/{$a}/published", $this->h())->assertOk();

        $sess = $this->actingAs($this->approver)->postJson('/api/v1/chat/sessions', [], $this->h())->json('data.id');
        $m = $this->actingAs($this->approver)->postJson("/api/v1/chat/sessions/{$sess}/messages", ['content' => 'Anything?'], $this->h())->assertOk()->json('data');
        $this->actingAs($this->approver)->postJson("/api/v1/chat/messages/{$m['id']}/rating", ['helpful' => false], $this->h())->assertOk();

        $this->actingAs($this->editor)->getJson('/api/v1/analytics/overview', $this->h())->assertStatus(403);
        $o = $this->actingAs($this->admin)->getJson('/api/v1/analytics/overview', $this->h())->assertOk()->json('data');
        $this->assertSame(['draft' => 1, 'in_review' => 0, 'approved' => 2, 'archived' => 0], $o['documents_by_state']);
        $this->assertSame(1, $o['past_review_by_space'][0]['count']);
        $this->assertSame(2, $o['approvals_30d']);
        $this->assertSame(1, $o['chat']['questions_30d']);
        $this->assertSame(1, $o['chat']['unique_askers_30d']);
        $this->assertSame(0, $o['chat']['helpful_rate_pct']);
        $this->assertSame($a, $o['most_read'][0]['document_id']);
        $this->assertSame(2, $o['most_read'][0]['reads']);
    }
}
