<?php

declare(strict_types=1);

namespace Tests\Feature\Handbook;

use App\Models\DocumentStep;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AcknowledgementDueNotification;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** Epic I: FR-701..707, BRL-07; S16/S17 acceptance boxes. */
final class AcknowledgementTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private Workspace $ws;

    private User $admin;

    private User $editor;

    private User $approver;

    private User $reader;

    private string $sid;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [$this->ws, $this->admin] = $this->makeWorkspace('acme');
        $this->sid = app(CurrentWorkspace::class)->runAs($this->ws->id, function (): string {
            $s = Space::query()->firstOrFail();
            $s->forceFill(['is_handbook' => true])->save();

            return $s->id;
        });
        $this->editor = $this->addMember($this->ws, 'ed@example.test', 'editor');
        $this->approver = $this->addMember($this->ws, 'ap@example.test', 'approver');
        $this->reader = $this->addMember($this->ws, 'rd@example.test', 'reader');
        app(CurrentWorkspace::class)->runAs($this->ws->id, function (): void {
            SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->editor->id, 'role' => 'editor']);
            SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->approver->id, 'role' => 'approver']);
            SpaceMember::create(['space_id' => $this->sid, 'user_id' => $this->reader->id, 'role' => 'reader']);
        });
    }

    private function h(): array
    {
        return $this->wsHeaders($this->ws);
    }

    private function approved(string $title): string
    {
        $id = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => $title, 'template_id' => 'onboarding'], $this->h())->json('data.id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Read this.']], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();

        return $id;
    }

    private function republish(string $id): void
    {
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Read this again.']], $this->h())->assertOk();
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => DocumentStep::query()->where('document_id', $id)->update(['verified_at' => now()]));
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();
    }

    public function test_acknowledgement_binds_to_a_version_and_a_new_version_re_triggers_it(): void
    {
        $id = $this->approved('Code of conduct');

        // A reader who is not a target sees no acknowledgement bar and cannot acknowledge.
        $hb = $this->actingAs($this->reader)->getJson('/api/v1/handbook', $this->h())->assertOk();
        $this->assertFalse($hb->json('data.documents.0.ack_required_from_me'));
        $this->actingAs($this->reader)->postJson("/api/v1/documents/{$id}/acknowledge", [], $this->h())->assertStatus(403);

        // Editors cannot assign targets; approver on the handbook space ("HR") can.
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/acknowledgement-targets", ['user_ids' => [$this->reader->id]], $this->h())->assertStatus(403);
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/acknowledgement-targets", ['user_ids' => [$this->reader->id, 'NOTAMEMBER0000000000000000']], $this->h())
            ->assertOk()->assertJsonPath('data.user_ids', [$this->reader->id])->assertJsonPath('data.requires_ack', true);
        Notification::assertSentTo($this->reader, AcknowledgementDueNotification::class, fn ($n) => $n->version === 1 && ! $n->reminder);

        $hb = $this->actingAs($this->reader)->getJson('/api/v1/handbook', $this->h())->assertOk();
        $this->assertTrue($hb->json('data.documents.0.ack_required_from_me'));
        $this->assertNull($hb->json('data.documents.0.acknowledged_at'));

        // Acknowledge: recorded against version 1 with user and timestamp; idempotent.
        $r = $this->actingAs($this->reader)->postJson("/api/v1/documents/{$id}/acknowledge", [], $this->h())->assertOk();
        $this->assertSame(1, $r->json('data.version_number'));
        $this->assertSame($this->reader->id, $r->json('data.user.id'));
        $this->assertNotNull($r->json('data.acknowledged_at'));
        $again = $this->actingAs($this->reader)->postJson("/api/v1/documents/{$id}/acknowledge", [], $this->h())->assertOk();
        $this->assertSame($r->json('data.acknowledged_at'), $again->json('data.acknowledged_at'));

        $rows = $this->actingAs($this->admin)->getJson('/api/v1/acknowledgements', $this->h())->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('done', $rows[0]['status']);
        $this->assertSame([], $this->actingAs($this->admin)->getJson('/api/v1/acknowledgements?status=outstanding', $this->h())->json('data'));

        // New approved version → outstanding again (FR-704, S16 acceptance), and the target is told.
        Notification::fake();
        $this->republish($id);
        Notification::assertSentTo($this->reader, AcknowledgementDueNotification::class, fn ($n) => $n->version === 2);
        $rows = $this->actingAs($this->admin)->getJson('/api/v1/acknowledgements?status=outstanding', $this->h())->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['version_number']);
        $hb = $this->actingAs($this->reader)->getJson('/api/v1/handbook', $this->h());
        $this->assertNull($hb->json('data.documents.0.acknowledged_at'));

        // Nudge → reminder to outstanding only; export → one row per user per version.
        Notification::fake();
        $this->actingAs($this->approver)->postJson('/api/v1/acknowledgements/remind', ['document_id' => $id], $this->h())->assertOk()->assertJsonPath('data.reminded', 1);
        Notification::assertSentTo($this->reader, AcknowledgementDueNotification::class, fn ($n) => $n->reminder);
        $csv = $this->actingAs($this->admin)->get('/api/v1/acknowledgements/export', $this->h())->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('user_name,user_email,document_id,document_title,version_number,status,acknowledged_at', $lines[0]);
        $this->assertStringContainsString(',2,outstanding,', $lines[1]);

        // A reader cannot see the compliance view.
        $this->actingAs($this->reader)->getJson('/api/v1/acknowledgements', $this->h())->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_handbook_order_is_set_by_the_space_owner_not_alphabetical(): void
    {
        $a = $this->approved('Alpha policy');
        $z = $this->approved('Zulu policy');
        $ids = collect($this->actingAs($this->reader)->getJson('/api/v1/handbook', $this->h())->json('data.documents'))->pluck('id')->all();
        $this->assertSame([$a, $z], $ids, 'unordered handbooks fall back to title');

        $this->actingAs($this->reader)->putJson('/api/v1/handbook/order', ['space_id' => $this->sid, 'order' => [$z, $a]], $this->h())->assertStatus(403);
        $r = $this->actingAs($this->approver)->putJson('/api/v1/handbook/order', ['space_id' => $this->sid, 'order' => [$z, $a]], $this->h())->assertOk();
        $this->assertSame([$z, $a], collect($r->json('data.documents'))->pluck('id')->all());
        $this->assertTrue($r->json('data.can_reorder'));
        $this->assertFalse($this->actingAs($this->reader)->getJson('/api/v1/handbook', $this->h())->json('data.can_reorder'));
    }
}
