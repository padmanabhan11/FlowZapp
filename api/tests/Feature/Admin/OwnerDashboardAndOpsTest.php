<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AcknowledgementTarget;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** L2-T2 owner dashboard and past-review list; L3-T2 alert routing; L4-T2 cost report. */
final class OwnerDashboardAndOpsTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private Workspace $ws;

    private User $admin;

    private User $approver;

    private User $owner;

    private string $sid;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [$this->ws, $this->admin] = $this->makeWorkspace('acme', 'team');
        $this->sid = app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Space::query()->firstOrFail()->id);
        $this->approver = $this->addMember($this->ws, 'ap@example.test', 'approver');
        $this->owner = $this->addMember($this->ws, 'own@example.test', 'editor');
        app(CurrentWorkspace::class)->runAs($this->ws->id, function (): void {
            SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->approver->id, 'role' => 'approver']);
            SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->owner->id, 'role' => 'editor']);
        });
    }

    /** @return array<string,string> */
    private function h(): array
    {
        return $this->wsHeaders($this->ws);
    }

    private function approved(string $title, array $extra = []): string
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => $title], $this->h())->json('data.id');
        $this->actingAs($this->owner)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'P.']] + $extra, $this->h())->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => 'Do it.'], $this->h())->assertStatus(201);
        $this->actingAs($this->owner)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();

        return $id;
    }

    public function test_owner_dashboard_lists_what_each_owned_document_needs_most_urgent_first(): void
    {
        $overdue = $this->approved('Refund Policy');
        $soon = $this->approved('Expense Claims');
        $fine = $this->approved('Client Onboarding');
        $ack = $this->approved('Code of Conduct', ['requires_ack' => true]);
        $draft = $this->actingAs($this->owner)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => 'New idea'], $this->h())->json('data.id');
        $this->approved('Someone else', []);   // owned by owner too; reassign to admin below
        app(CurrentWorkspace::class)->runAs($this->ws->id, function () use ($overdue, $soon, $ack): void {
            Document::query()->whereKey($overdue)->update(['review_due_at' => now()->subDays(3)]);
            Document::query()->whereKey($soon)->update(['review_due_at' => now()->addDays(10)]);
            Document::query()->where('title', 'Someone else')->update(['owner_id' => $this->admin->id]);
            AcknowledgementTarget::create(['document_id' => $ack, 'user_id' => $this->approver->id, 'assigned_by' => $this->admin->id]);
        });

        $r = $this->actingAs($this->owner)->getJson('/api/v1/me/documents', $this->h())->assertOk()->json('data');
        $this->assertSame(['total' => 5, 'needing_attention' => 4, 'review_overdue' => 1, 'review_soon' => 1], $r['summary']);
        $byId = collect($r['documents'])->keyBy('id');
        $this->assertSame(['review_overdue'], $byId[$overdue]['needs']);
        $this->assertSame(['review_soon'], $byId[$soon]['needs']);
        $this->assertSame([], $byId[$fine]['needs']);
        $this->assertSame(['acknowledgements_outstanding'], $byId[$ack]['needs']);
        $this->assertSame(1, $byId[$ack]['acknowledgements_outstanding']);
        $this->assertSame(['draft'], $byId[$draft]['needs']);
        $this->assertSame($overdue, $r['documents'][0]['id'], 'overdue review comes first');
        $this->assertSame($fine, $r['documents'][4]['id'], 'nothing to do goes last');
        $this->assertNotContains('Someone else', array_column($r['documents'], 'title'));

        // Admin past-review list, most overdue first; not for editors.
        $this->actingAs($this->owner)->getJson('/api/v1/analytics/past-review', $this->h())->assertStatus(403);
        $list = $this->actingAs($this->admin)->getJson('/api/v1/analytics/past-review', $this->h())->assertOk()->json('data');
        $this->assertSame([$overdue], array_column($list, 'id'));
        $this->assertSame(3, $list[0]['days_overdue']);
        $this->assertSame('Own', $list[0]['owner']['name']);
    }

    public function test_index_alert_goes_to_the_alerts_channel(): void
    {
        $id = $this->approved('Refund Policy');
        app(CurrentWorkspace::class)->runAs($this->ws->id, function () use ($id): void {
            DocumentChunk::query()->where('document_id', $id)->delete();
            DocumentVersion::query()->where('document_id', $id)->update(['approved_at' => now()->subHours(2)]);
        });
        Log::shouldReceive('channel')->with('alerts')->once()->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn ($msg, $ctx) => $msg === 'retrieval.unindexed_approved_documents' && $ctx['documents'] === [$id]);
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->artisan('retrieval:check-index')->assertSuccessful();
    }

    public function test_cost_report_sums_pipeline_chat_and_assistance_spend_per_workspace(): void
    {
        $this->approved('Refund Policy');
        app(CurrentWorkspace::class)->runAs($this->ws->id, function (): void {
            $rec = Recording::create(['space_id' => $this->sid, 'uploaded_by' => $this->owner->id, 'title' => 'r', 'storage_key' => 'k/source.webm', 'state' => 'draft_ready', 'duration_sec' => 300]);
            foreach ([['transcribe', 0.03], ['segment', 0.02], ['frames', 0.0], ['generate', 0.15]] as [$stage, $cost]) {
                PipelineJob::create(['recording_id' => $rec->id, 'stage' => $stage, 'job_key' => "{$rec->id}:{$stage}:h", 'status' => 'succeeded', 'cost_usd' => $cost]);
            }
            PipelineJob::create(['recording_id' => $rec->id, 'stage' => 'generate', 'job_key' => "{$rec->id}:generate:h2", 'status' => 'failed', 'cost_usd' => 0.05]);
            $s = ChatSession::create(['user_id' => $this->owner->id]);
            ChatMessage::create(['session_id' => $s->id, 'role' => 'user', 'content' => 'q']);
            ChatMessage::create(['session_id' => $s->id, 'role' => 'assistant', 'content' => 'a', 'refused' => false, 'cost_usd' => 0.004]);
        });
        $csv = sys_get_temp_dir().'/cost-'.uniqid().'.csv';

        $this->artisan('ops:cost-report', ['--csv' => $csv])->expectsOutputToContain('All workspaces: 1 SOPs, $0.2500 pipeline (avg $0.2500 per SOP), $0.0040 chat, $0.0000 assist.')->assertSuccessful();
        $rows = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES) ?: []);
        $this->assertSame('workspace', $rows[0][0]);
        $acme = array_combine($rows[0], $rows[1]);
        $this->assertSame(['Acme', 'team', '1', '0.25', '0.25', '1', '0.004', '99', '99.7'], [$acme['workspace'], $acme['plan'], $acme['sops'], $acme['pipeline_usd'], $acme['per_sop_usd'], $acme['answers'], $acme['chat_usd'], $acme['plan_usd'], $acme['margin_pct']]);
    }
}
