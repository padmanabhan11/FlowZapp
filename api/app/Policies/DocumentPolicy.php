<?php

declare(strict_types=1);

namespace App\Policies;

use App\Access\Access;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Space;
use App\Models\User;

/**
 * Document access rides on SpacePolicy plus state (14 §4 role matrix):
 *  - approved / in_review: anyone who can view the space (readers see the approved version);
 *  - draft: its author, its owner, or someone who can approve in the space;
 *  - archived: viewable by editors and above with a banner, never by readers.
 */
final class DocumentPolicy
{
    public function __construct(private readonly SpacePolicy $spaces) {}

    public function view(User $user, Document $doc): bool
    {
        $role = $this->roleFor($user, $doc);
        if ($role === null) {
            return false;
        }

        return match ($doc->state) {
            'draft' => $this->isAuthorOrOwner($user, $doc) || Access::atLeast($role, 'approver'),
            'archived' => Access::atLeast($role, 'editor'),
            default => true,
        };
    }

    /** Effective role on the document's folder (or space when unfiled), with folder overrides applied (F10). */
    public function roleFor(User $user, Document $doc): ?string
    {
        $folder = $doc->folder_id ? Folder::query()->find($doc->folder_id) : null;

        return Access::resolve($user, $this->space($doc), $folder)['role'];
    }

    /** Edit the working copy. Editing an approved document creates a draft revision (FR-408) — same permission. */
    public function edit(User $user, Document $doc): bool
    {
        if ($doc->state === 'archived') {
            return false;
        }
        $role = $this->roleFor($user, $doc);
        if (! Access::atLeast($role, 'editor')) {
            return false;
        }

        return $doc->state !== 'draft' || $this->isAuthorOrOwner($user, $doc) || Access::atLeast($role, 'approver');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->spaces->edit($user, $space);
    }

    public function archive(User $user, Document $doc): bool
    {
        return Access::atLeast($this->roleFor($user, $doc), 'approver');
    }

    public function delete(User $user, Document $doc): bool
    {
        return Access::atLeast($this->roleFor($user, $doc), 'approver') || ($this->isAuthorOrOwner($user, $doc) && Access::atLeast($this->roleFor($user, $doc), 'editor'));
    }

    private function isAuthorOrOwner(User $user, Document $doc): bool
    {
        return $doc->created_by === $user->getKey() || $doc->owner_id === $user->getKey();
    }

    private function space(Document $doc): Space
    {
        return $doc->relationLoaded('space') ? $doc->space : Space::query()->findOrFail($doc->space_id);
    }
}
