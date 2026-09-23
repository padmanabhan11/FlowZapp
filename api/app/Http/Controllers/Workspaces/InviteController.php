<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Audit\Audit;
use App\Billing\Plans;
use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvite;
use App\Models\WorkspaceMember;
use App\Notifications\WorkspaceInviteNotification;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Invitations (FR-105..107, S3). Seat limits are checked before sending, not
 * after acceptance, so an invite never dead-ends at the door (FR-106).
 */
final class InviteController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /v1/workspaces/{workspace}/invites — pending invites (admin). */
    public function index(Workspace $workspace): JsonResponse
    {
        $this->guard($workspace);

        $rows = WorkspaceInvite::query()->whereNull('accepted_at')->whereNull('revoked_at')
            ->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map(fn (WorkspaceInvite $i) => $this->present($i))]);
    }

    /**
     * POST /v1/workspaces/{workspace}/invites
     * body { invites: [{ email, role, space_ids? }] } — per-row results so one bad
     * address never rejects the batch (S3 acceptance).
     */
    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->guard($workspace);
        $data = $request->validate([
            'invites' => ['required', 'array', 'min:1', 'max:50'],
            'invites.*.email' => ['required', 'email:rfc', 'max:190'],
            'invites.*.role' => ['required', Rule::in(WorkspaceMember::ROLES)],
            'invites.*.space_ids' => ['nullable', 'array'],
            'invites.*.space_ids.*' => ['string', 'size:26'],
        ]);

        $emails = $request->collect('invites')->pluck('email')->map(fn ($e) => Str::lower($e))->unique();
        $existing = User::query()->whereIn('email', $emails)->get()->keyBy('email');
        $memberUserIds = WorkspaceMember::query()->pluck('user_id')->all();
        $pendingEmails = WorkspaceInvite::query()->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->pluck('email')->all();
        $validSpaceIds = Space::query()->pluck('id')->all();

        $seatLimit = app(Plans::class)->seatLimit($workspace);   // K2: billed seats
        $seatsUsed = count($memberUserIds) + count($pendingEmails);

        $results = [];
        $inviter = $request->user();
        foreach ($data['invites'] as $row) {
            $email = Str::lower($row['email']);
            $user = $existing->get($email);

            if ($user !== null && in_array($user->id, $memberUserIds, true)) {
                $results[] = ['email' => $email, 'status' => 'already_member', 'message' => "$email is already a member."];

                continue;
            }
            if (in_array($email, $pendingEmails, true)) {
                $results[] = ['email' => $email, 'status' => 'already_invited', 'message' => "$email already has a pending invite."];

                continue;
            }
            if ($seatLimit !== null && $seatsUsed >= $seatLimit) {
                $results[] = ['email' => $email, 'status' => 'plan_limit_exceeded',
                    'message' => ucfirst($workspace->plan)." includes $seatLimit people. Upgrade to invite more.",
                    'details' => ['limit' => 'seats', 'max' => $seatLimit, 'used' => $seatsUsed]];

                continue;
            }

            $spaceIds = array_values(array_intersect($row['space_ids'] ?? [], $validSpaceIds));
            $invite = $this->issue($workspace, $inviter, $email, $row['role'], $spaceIds);
            $pendingEmails[] = $email;
            $seatsUsed++;
            $results[] = ['email' => $email, 'status' => 'sent', 'invite' => $this->present($invite)];
        }

        $anySent = collect($results)->contains('status', 'sent');
        $allLimited = collect($results)->every(fn ($r) => $r['status'] === 'plan_limit_exceeded');

        return response()->json(['data' => $results], $allLimited ? 429 : ($anySent ? 201 : 200));
    }

    /** POST /v1/workspaces/{workspace}/invites/{invite}/resend — new token, old invalidated. */
    public function resend(Request $request, Workspace $workspace, string $inviteId): JsonResponse
    {
        $this->guard($workspace);
        /**
         * @var WorkspaceInvite $invite
         */
        $invite = WorkspaceInvite::query()->findOrFail($inviteId);
        abort_unless($invite->accepted_at === null, 409, 'Already accepted.');

        $invite->forceFill(['revoked_at' => now()])->save();
        $fresh = $this->issue($workspace, $request->user(), $invite->email, $invite->role, $invite->space_ids ?? []);

        return response()->json(['data' => $this->present($fresh)], 201);
    }

    /** DELETE /v1/workspaces/{workspace}/invites/{invite} — revoke (FR-107). */
    public function destroy(Workspace $workspace, string $inviteId): JsonResponse
    {
        $this->guard($workspace);
        /**
         * @var WorkspaceInvite $invite
         */
        $invite = WorkspaceInvite::query()->findOrFail($inviteId);
        if ($invite->isOpen()) {
            $invite->forceFill(['revoked_at' => now()])->save();
            Audit::record('invite.revoked', 'workspace_invite', $invite->id, ['email' => $invite->email]);
        }

        return response()->json(['data' => $this->present($invite)]);
    }

    /**
     * POST /v1/invites/accept  body { token } — authenticated, no X-Workspace-Id yet.
     * The signed-in user's email must match the invitation.
     */
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $user = $request->user();

        /**
         * @var WorkspaceInvite|null $invite
         */
        $invite = WorkspaceInvite::withoutGlobalScopes() // allowlisted: token lookup precedes tenant resolution; token is unique and unguessable
            ->where('token_hash', hash('sha256', $data['token']))->first();

        if ($invite === null || ! $invite->isOpen()) {
            return response()->json(['error' => ['code' => 'invite_invalid', 'message' => 'That invitation is no longer valid. Ask for a new one.']], 410);
        }
        if (Str::lower($invite->email) !== Str::lower($user->email)) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Not permitted.']], 403);
        }

        $workspace = Workspace::query()->findOrFail($invite->workspace_id);

        $this->current->runAs($workspace->id, function () use ($invite, $user): void {
            DB::transaction(function () use ($invite, $user): void {
                if (! WorkspaceMember::query()->where('user_id', $user->id)->exists()) {
                    WorkspaceMember::create(['user_id' => $user->id, 'role' => $invite->role, 'invited_by' => $invite->created_by, 'joined_at' => now()]);
                }
                foreach ($invite->space_ids ?? [] as $spaceId) {
                    SpaceMember::query()->firstOrCreate(['space_id' => $spaceId, 'user_id' => $user->id], ['role' => $invite->role]);
                }
                $invite->forceFill(['accepted_at' => now()])->save();
                Audit::record('member.joined', 'user', $user->id, ['via' => 'invite', 'role' => $invite->role]);
            });
        });

        return response()->json(['data' => [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug, 'plan' => $workspace->plan],
            'role' => $invite->role,
        ]]);
    }

    /**
     * @param  list<string>  $spaceIds
     */
    private function issue(Workspace $workspace, User $inviter, string $email, string $role, array $spaceIds): WorkspaceInvite
    {
        $token = Str::random(64);
        $invite = WorkspaceInvite::create([
            'email' => $email,
            'role' => $role,
            'space_ids' => $spaceIds,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('flowzapp.invite_ttl_days')),
            'created_by' => $inviter->id,
        ]);

        $url = rtrim((string) config('flowzapp.frontend_url'), '/').'/auth?invite='.$token;
        Notification::route('mail', $email)->notify(
            new WorkspaceInviteNotification($workspace->name, $inviter->name, $role, $url)
        );
        Audit::record('invite.sent', 'workspace_invite', $invite->id, ['email' => $email, 'role' => $role]);

        return $invite;
    }

    private function guard(Workspace $workspace): void
    {
        abort_unless($workspace->id === $this->current->id(), 403, 'Not permitted.');
        $this->authorize('workspace-admin');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WorkspaceInvite $i): array
    {
        return [
            'id' => $i->id, 'email' => $i->email, 'role' => $i->role, 'space_ids' => $i->space_ids ?? [],
            'expires_at' => $i->expires_at, 'accepted_at' => $i->accepted_at, 'revoked_at' => $i->revoked_at,
            'status' => $i->accepted_at ? 'accepted' : ($i->revoked_at ? 'revoked' : ($i->expires_at->isPast() ? 'expired' : 'pending')),
        ];
    }
}
