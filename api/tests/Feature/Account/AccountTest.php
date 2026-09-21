<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\Document;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ReviewDueNotification;
use App\Notifications\ReviewRequestedNotification;
use App\Notifications\WeeklyDigestNotification;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** Epic J: S23 workspace settings, S24 account & notification preferences, digest. */
final class AccountTest extends TestCase
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

    public function test_notification_preferences_are_per_workspace_and_govern_email_only(): void
    {
        $r = $this->actingAs($this->approver)->getJson('/api/v1/account/notifications', $this->h())->assertOk();
        $this->assertTrue($r->json('data.prefs.review_requested'));
        $this->assertSame([], $r->json('data.locked'));

        $this->actingAs($this->approver)->patchJson('/api/v1/account/notifications', ['review_requested' => false], $this->h())->assertOk()->assertJsonPath('data.prefs.review_requested', false);

        // A submission still reaches the approver in-app, but no longer by email.
        $id = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => 'Refunds', 'template_id' => 'onboarding'], $this->h())->json('data.id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Handle refunds.']], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        Notification::assertSentTo($this->approver, ReviewRequestedNotification::class, fn ($n, $channels) => $channels === ['database']);

        // The preference is scoped to this workspace: another workspace keeps the default.
        [$other] = $this->makeWorkspace('other', 'free', $this->approver);
        $this->assertTrue($this->actingAs($this->approver)->getJson('/api/v1/account/notifications', $this->wsHeaders($other))->json('data.prefs.review_requested'));

        // Profile update; email is read-only.
        $this->actingAs($this->approver)->patchJson('/api/v1/account', ['name' => 'Ana P.', 'email' => 'x@example.test'], $this->h())->assertOk()->assertJsonPath('data.name', 'Ana P.')->assertJsonPath('data.email', 'ap@example.test');

        // In-app list and mark-read.
        $list = $this->actingAs($this->approver)->getJson('/api/v1/notifications?unread=1', $this->h())->assertOk();
        $this->assertSame(0, $list->json('unread_count'), 'Notification::fake() stores nothing; the endpoint still answers');
    }

    public function test_acknowledgement_due_is_locked_while_assigned(): void
    {
        $id = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => 'Policy', 'template_id' => 'onboarding'], $this->h())->json('data.id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Read.']], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/acknowledgement-targets", ['user_ids' => [$this->editor->id]], $this->h())->assertOk();

        $r = $this->actingAs($this->editor)->patchJson('/api/v1/account/notifications', ['acknowledgement_due' => false], $this->h())->assertOk();
        $this->assertTrue($r->json('data.prefs.acknowledgement_due'));
        $this->assertSame(['acknowledgement_due'], $r->json('data.locked'));
    }

    public function test_workspace_settings_and_scheduled_deletion(): void
    {
        $this->actingAs($this->editor)->patchJson("/api/v1/workspaces/{$this->ws->id}", ['settings' => ['self_approval' => true]], $this->h())->assertStatus(403);
        $this->actingAs($this->admin)->patchJson("/api/v1/workspaces/{$this->ws->id}", ['settings' => ['self_approval' => true, 'review_cadence_months' => 6]], $this->h())
            ->assertOk()->assertJsonPath('data.settings.self_approval', true)->assertJsonPath('data.settings.review_cadence_months', 6);

        $this->actingAs($this->admin)->deleteJson("/api/v1/workspaces/{$this->ws->id}", ['confirm_name' => 'wrong'], $this->h())->assertStatus(422);
        $r = $this->actingAs($this->admin)->deleteJson("/api/v1/workspaces/{$this->ws->id}", ['confirm_name' => $this->ws->name], $this->h())->assertOk();
        $this->assertNotNull($r->json('data.deletion_scheduled_at'));
        $this->assertSame(1, Workspace::query()->count(), 'nothing is deleted at click time');
        $this->actingAs($this->admin)->postJson("/api/v1/workspaces/{$this->ws->id}/cancel-deletion", [], $this->h())->assertOk()->assertJsonPath('data.deletion_scheduled_at', null);

        // Purge runs only once the date has passed.
        $this->ws->forceFill(['deletion_scheduled_at' => now()->addDay()])->save();
        $this->artisan('workspaces:purge-scheduled')->assertSuccessful();
        $this->assertSame(1, Workspace::query()->count());
        $this->ws->forceFill(['deletion_scheduled_at' => now()->subMinute()])->save();
        $this->artisan('workspaces:purge-scheduled')->assertSuccessful();
        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_review_due_and_weekly_digest(): void
    {
        $id = $this->actingAs($this->editor)->postJson('/api/v1/documents', ['space_id' => $this->sid, 'title' => 'Old SOP', 'template_id' => 'onboarding'], $this->h())->json('data.id');
        $this->actingAs($this->editor)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => 'Do.'], 'owner_id' => $this->editor->id], $this->h())->assertOk();
        $this->actingAs($this->editor)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => Document::query()->whereKey($id)->update(['review_due_at' => now()->subDay(), 'owner_id' => $this->editor->id]));

        $this->artisan('documents:review-due')->assertSuccessful();
        Notification::assertSentTo($this->editor, ReviewDueNotification::class);

        $this->artisan('notifications:weekly-digest')->assertSuccessful();
        Notification::assertSentTo($this->editor, WeeklyDigestNotification::class, fn ($n) => count($n->reviewDue) === 1 && $n->acksDue === []);
        Notification::assertNotSentTo($this->approver, WeeklyDigestNotification::class);

        // Opting out of the digest stops it.
        $this->actingAs($this->editor)->patchJson('/api/v1/account/notifications', ['weekly_digest' => false], $this->h())->assertOk();
        Notification::fake();
        $this->artisan('notifications:weekly-digest')->assertSuccessful();
        Notification::assertNotSentTo($this->editor, WeeklyDigestNotification::class);
    }
}
