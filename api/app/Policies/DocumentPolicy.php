<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
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
        $space = $this->space($doc);
        if (! $this->spaces->view($user, $space)) {
            return false;
        }

        return match ($doc->state) {
            'draft' => $this->isAuthorOrOwner($user, $doc) || $this->spaces->approve($user, $space),
            'archived' => $this->spaces->edit($user, $space),
            default => true,
        };
    }

    /** Edit the working copy. Editing an approved document creates a draft revision (FR-408) — same permission. */
    public function edit(User $user, Document $doc): bool
    {
        if ($doc->state === 'archived') {
            return false;
        }
        $space = $this->space($doc);
        if (! $this->spaces->edit($user, $space)) {
            return false;
        }

        return $doc->state !== 'draft' || $this->isAuthorOrOwner($user, $doc) || $this->spaces->approve($user, $space);
    }

    public function create(User $user, Space $space): bool
    {
        return $this->spaces->edit($user, $space);
    }

    public function archive(User $user, Document $doc): bool
    {
        return $this->spaces->approve($user, $this->space($doc));
    }

    public function delete(User $user, Document $doc): bool
    {
        return $this->spaces->approve($user, $this->space($doc)) || $this->isAuthorOrOwner($user, $doc);
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
