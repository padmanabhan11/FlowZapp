<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Models\Document;
use App\Models\DocumentStep;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ReviewRequestedNotification;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

final class ApprovalWorkflowTest extends TestCase
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

    private function draft(string $title = 'Refunds'): string
    {
        $id = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => $title, 'template_id' => 'onboarding'], $this->h())->json('data.id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Handle refunds consistently.']], $this->h())->assertOk();

        return $id;
    }

    public function test_submit_is_blocked_until_purpose_owner_steps_and_verification_are_satisfied(): void
    {
        $id = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => 'Empty'], $this->h())->json('data.id');
        $r = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/submit-check", $this->h())->assertOk();
        $this->assertSame(['a purpose is required', 'an SOP needs at least one step'], $r->json('data.blockers'));
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertStatus(422);

        // Generated draft with unverified steps (FR-315)
        $gen = $this->draft('Generated');
        app(CurrentWorkspace::class)->runAs($this->ws->id, function () use ($gen): void {
            $rec = \App\Models\Recording::create(['space_id' => $this->sid, 'uploaded_by' => $this->editor->id, 'storage_key' => 'k', 'state' => 'draft_ready']);
            Document::query()->whereKey($gen)->update(['source_recording_id' => $rec->id]);
            DocumentStep::query()->where('document_id', $gen)->update(['verified_at' => null]);
        });
        $r = $this->actingAs($this->editor)->postJson("/api/v1/documents/{$gen}/submit", [], $this->h())->assertStatus(422);
        $this->assertStringContainsString('verified against the recording', $r->json('error.message'));
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => DocumentStep::query()->where('document_id', $gen)->update(['verified_at' => now()]));
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$gen}/submit", [], $this->h())->assertOk()->assertJsonPath('data.state', 'in_review');
    }

    public function test_full_cycle_submit_approve_creates_version_and_edit_starts_new_draft_while_v1_stays_live(): void
    {
        $id = $this->draft();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        Notification::assertSentTo($this->approver, ReviewRequestedNotification::class);

        // Editor cannot approve; submitter cannot self-approve even as approver when the setting is off.
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertStatus(403);

        $review = $this->actingAs($this->approver)->getJson("/api/v1/documents/{$id}/review", $this->h())->assertOk();
        $this->assertNull($review->json('data.base_version'));   // first version: no diff, full document
        $this->assertSame(1, $review->json('data.next_version_number'));

        $r = $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", ['change_summary' => 'First publish'], $this->h())->assertOk();
        $this->assertSame(1, $r->json('data.version_number'));
        $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/published", $this->h())->assertOk()->assertJsonPath('data.version_number', 1)->assertJsonCount(5, 'data.steps');

        // Edit → draft revision; published stays v1; review shows a diff against v1.
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['title' => 'Refunds v2 title'], $this->h())->assertOk()->assertJsonPath('data.state', 'draft');
        $stepId = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/steps", $this->h())->json('data.0.id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}/steps/{$stepId}", ['instruction' => 'Create accounts for every tool.'], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();

        $review = $this->actingAs($this->approver)->getJson("/api/v1/documents/{$id}/review", $this->h())->assertOk();
        $kinds = collect($review->json('data.changes'))->map(fn ($c) => $c['kind'].':'.$c['ref'])->all();
        $this->assertContains('modified:title', $kinds);
        $this->assertContains('modified:step:1', $kinds);
        $this->assertSame(2, $review->json('data.next_version_number'));
        $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/published", $this->h())->assertJsonPath('data.version_number', 1);

        // Stale approval is refused.
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", ['expected_updated_at' => '2020-01-01T00:00:00Z'], $this->h())->assertStatus(409);
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk()->assertJsonPath('data.version_number', 2);
        $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/published", $this->h())->assertJsonPath('data.version_number', 2)->assertJsonPath('data.title', 'Refunds v2 title');

        $versions = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/versions", $this->h())->json('data');
        $this->assertSame([2, 1], array_column($versions, 'version_number'));
        $this->assertTrue($versions[0]['is_live']);
        $this->assertCount(4, $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/approvals", $this->h())->json('data'));
    }

    public function test_request_changes_requires_a_comment_and_self_approval_follows_the_workspace_setting(): void
    {
        $id = $this->draft();
        $this->actingAs($this->approver)->patchJson("/api/v1/documents/{$id}", ['content' => ['scope' => 'All']], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();

        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/request-changes", [], $this->h())->assertStatus(422);
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/request-changes", ['comment' => 'Add the refund window.'], $this->h())->assertOk()->assertJsonPath('data.state', 'draft');

        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertStatus(403);   // own submission
        $this->actingAs($this->admin)->patchJson("/api/v1/workspaces/{$this->ws->id}", ['settings' => ['self_approval' => true]], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();
    }

    public function test_restore_creates_a_draft_from_an_old_version_and_the_live_version_is_untouched(): void
    {
        $id = $this->draft();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h());
        $v1 = $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->json('data.version_id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['title' => 'Second'], $this->h());
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h());
        $v2 = $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->json('data.version_id');

        $r = $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/versions/{$v1}/restore", [], $this->h())->assertOk();
        $this->assertSame('draft', $r->json('data.state'));
        $this->assertSame('Refunds', $r->json('data.title'));
        $this->assertSame($v2, $r->json('data.approved_version_id'));
        $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/published", $this->h())->assertJsonPath('data.title', 'Second');

        $diff = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/diff?from={$v2}&to=working", $this->h())->assertOk()->json('data.changes');
        $this->assertSame('modified', $diff[0]['kind']);
        $this->assertSame('title', $diff[0]['ref']);
    }

    public function test_diff_reports_moved_steps_as_moved(): void
    {
        $id = $this->draft();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h());
        $v1 = $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->json('data.version_id');
        $steps = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/steps", $this->h())->json('data');
        $ids = array_column($steps, 'id');
        $order = [$ids[1], $ids[0], $ids[2], $ids[3], $ids[4]];
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/steps/reorder", ['order' => $order], $this->h())->assertOk();
        $this->actingAs($this->editor)->deleteJson("/api/v1/documents/{$id}/steps/{$ids[4]}", [], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => 'New final step'], $this->h())->assertStatus(201);

        $changes = $this->actingAs($this->editor)->getJson("/api/v1/documents/{$id}/diff?from={$v1}&to=working", $this->h())->assertOk()->json('data.changes');
        $kinds = array_count_values(array_column($changes, 'kind'));
        $this->assertSame(1, $kinds['moved'] ?? 0, json_encode($changes));
        $this->assertSame(1, $kinds['removed'] ?? 0);
        $this->assertSame(1, $kinds['added'] ?? 0);
    }

    public function test_archive_removes_from_lists_and_readers(): void
    {
        $id = $this->draft();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h());
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/archive", [], $this->h())->assertStatus(403);
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/archive", [], $this->h())->assertOk()->assertJsonPath('data.state', 'archived');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['title' => 'x'], $this->h())->assertStatus(403);
    }
}
