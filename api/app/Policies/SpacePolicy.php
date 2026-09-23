<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;

/**
 * Space-level access (03 §4.1: "Space-level access is a Policy, not a scope").
 * Workspace scoping stops cross-tenant leaks; this decides which spaces inside
 * the tenant a person can see. Default deny (BR-22, FR-502): a new member sees
 * nothing until granted. Workspace admins see every space (F10 role table).
 */
final class SpacePolicy
{
    public function view(User $user, Space $space): bool
    {
        return $this->isWorkspaceAdmin() || $this->roleIn($user, $space) !== null;
    }

    /** Create/edit content in the space: editor, approver or admin of the space. */
    public function edit(User $user, Space $space): bool
    {
        return $this->isWorkspaceAdmin() || in_array($this->roleIn($user, $space), ['admin', 'approver', 'editor'], true);
    }

    /** Approve/archive within the space. */
    public function approve(User $user, Space $space): bool
    {
        return $this->isWorkspaceAdmin() || in_array($this->roleIn($user, $space), ['admin', 'approver'], true);
    }

    /** Manage the space itself and its members: space admin or workspace admin (S19: Admin). */
    public function manage(User $user, Space $space): bool
    {
        return $this->isWorkspaceAdmin() || $this->roleIn($user, $space) === 'admin';
    }

    public function create(User $user): bool
    {
        return $this->isWorkspaceAdmin();
    }

    private function isWorkspaceAdmin(): bool
    {
        return request()->attributes->get('workspace_role') === 'admin';
    }

    private function roleIn(User $user, Space $space): ?string
    {
        /**
         * @var SpaceMember|null $m
         */
        $m = SpaceMember::query()->where('space_id', $space->id)->where('user_id', $user->getKey())->first();

        return $m?->role;
    }
}
