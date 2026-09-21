<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Models\AuditEntry;
use App\Models\User;
use App\Models\WorkspaceInvite;
use App\Notifications\WorkspaceInviteNotification;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

final class InviteAndMemberTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    public function test_one_bad_address_does_not_block_the_batch_and_seat_limit_is_caught_at_invite_time(): void
    {
        Notification::fake();
        [$ws, $admin] = $this->makeWorkspace('acme'); // free: 3 seats, 1 used

        $res = $this->actingAs($admin)->postJson("/api/v1/workspaces/{$ws->id}/invites", ['invites' => [
            ['email' => 'one@example.test', 'role' => 'editor'],
            ['email' => 'two@example.test', 'role' => 'reader'],
            ['email' => 'three@example.test', 'role' => 'reader'],   // 4th seat on Free
        ]], $this->wsHeaders($ws));

        $res->assertStatus(201);
        $this->assertSame(['sent', 'sent', 'plan_limit_exceeded'], array_column($res->json('data'), 'status'));
        $this->assertSame(3, $res->json('data.2.details.max'));
        Notification::assertSentTimes(WorkspaceInviteNotification::class, 2);

        // Malformed email fails validation for the whole request (422) — the client validates per row first.
        $this->actingAs($admin)->postJson("/api/v1/workspaces/{$ws->id}/invites", ['invites' => [['email' => 'not-an-email', 'role' => 'editor']]], $this->wsHeaders($ws))
            ->assertStatus(422);
    }

    public function test_accepting_an_invite_creates_the_membership_with_the_invited_role_and_spaces(): void
    {
        Notification::fake();
        [$ws, $admin] = $this->makeWorkspace('acme');
        $spaceId = app(CurrentWorkspace::class)->runAs($ws->id, fn () => \App\Models\Space::query()->firstOrFail()->id);

        $this->actingAs($admin)->postJson("/api/v1/workspaces/{$ws->id}/invites", ['invites' => [
            ['email' => 'bo@example.test', 'role' => 'approver', 'space_ids' => [$spaceId]],
        ]], $this->wsHeaders($ws))->assertStatus(201);

        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInviteNotification::class, function (WorkspaceInviteNotification $n, array $c, AnonymousNotifiable $to) use (&$token): bool {
            parse_str((string) parse_url($n->toMail($to)->actionUrl, PHP_URL_QUERY), $q);
            $token = $q['invite'] ?? null;

            return true;
        });
        $this->assertIsString($token);

        $stranger = User::create(['name' => 'X', 'email' => 'x@example.test']);
        $this->actingAs($stranger)->postJson('/api/v1/invites/accept', ['token' => $token])->assertStatus(403);

        $bo = User::create(['name' => 'Bo', 'email' => 'bo@example.test']);
        $this->actingAs($bo)->postJson('/api/v1/invites/accept', ['token' => $token])->assertOk()->assertJsonPath('data.role', 'approver');
        $this->assertSame('approver', $bo->roleIn($ws->id));
        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($bo, $spaceId): void {
            $this->assertTrue(\App\Models\SpaceMember::query()->where('user_id', $bo->id)->where('space_id', $spaceId)->exists());
            $this->assertTrue(AuditEntry::query()->where('action', 'member.joined')->exists());
        });

        // Single use.
        $this->actingAs($bo)->postJson('/api/v1/invites/accept', ['token' => $token])->assertStatus(410);
    }

    public function test_revoked_invite_no_longer_works(): void
    {
        Notification::fake();
        [$ws, $admin] = $this->makeWorkspace('acme');
        $res = $this->actingAs($admin)->postJson("/api/v1/workspaces/{$ws->id}/invites", ['invites' => [['email' => 'bo@example.test', 'role' => 'reader']]], $this->wsHeaders($ws));
        $inviteId = $res->json('data.0.invite.id');

        $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$ws->id}/invites/{$inviteId}", [], $this->wsHeaders($ws))->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertFalse(app(CurrentWorkspace::class)->runAs($ws->id, fn () => WorkspaceInvite::query()->findOrFail($inviteId)->isOpen()));
    }

    public function test_last_admin_cannot_be_demoted_or_removed(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $editor = $this->addMember($ws, 'ed@example.test', 'editor');

        $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}/members/{$admin->id}", ['role' => 'editor'], $this->wsHeaders($ws))->assertStatus(422)->assertJsonPath('error.code', 'last_admin');
        $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$ws->id}/members/{$admin->id}", [], $this->wsHeaders($ws))->assertStatus(422);

        $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}/members/{$editor->id}", ['role' => 'admin'], $this->wsHeaders($ws))->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}/members/{$admin->id}", ['role' => 'editor'], $this->wsHeaders($ws))->assertOk();
        $this->assertSame('editor', $admin->roleIn($ws->id));
    }

    public function test_removing_a_member_revokes_access_immediately_and_non_admins_cannot_manage(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $editor = $this->addMember($ws, 'ed@example.test', 'editor');

        $this->actingAs($editor)->deleteJson("/api/v1/workspaces/{$ws->id}/members/{$admin->id}", [], $this->wsHeaders($ws))->assertStatus(403);

        $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$ws->id}/members/{$editor->id}", [], $this->wsHeaders($ws))->assertOk();
        $this->assertNull($editor->roleIn($ws->id));
        $this->actingAs($editor)->getJson('/api/v1/spaces', $this->wsHeaders($ws))->assertStatus(403);
    }
}
