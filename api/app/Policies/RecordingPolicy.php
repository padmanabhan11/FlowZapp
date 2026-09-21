<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Recording;
use App\Models\Space;
use App\Models\User;

/**
 * BRL-09 / FR-511: a recording is private to its uploader and workspace
 * administrators until a draft procedure derived from it exists; after that,
 * anyone who can view the derived document's space can play it back.
 */
final class RecordingPolicy
{
    public function __construct(private readonly SpacePolicy $spaces) {}

    public function view(User $user, Recording $rec): bool
    {
        if ($rec->uploaded_by === $user->getKey() || $this->isWorkspaceAdmin()) {
            return true;
        }
        if ($rec->document_id !== null && $rec->space_id !== null) {
            return $this->spaces->view($user, Space::query()->findOrFail($rec->space_id));
        }

        return false;
    }

    /** Retry, rename, regenerate, delete: uploader or admin (S10 actions). */
    public function manage(User $user, Recording $rec): bool
    {
        return $rec->uploaded_by === $user->getKey() || $this->isWorkspaceAdmin();
    }

    /** Editors and above record (14 §4). */
    public function create(User $user): bool
    {
        return in_array(request()->attributes->get('workspace_role'), ['admin', 'approver', 'editor'], true);
    }

    private function isWorkspaceAdmin(): bool
    {
        return request()->attributes->get('workspace_role') === 'admin';
    }
}
