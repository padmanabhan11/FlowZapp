<?php

declare(strict_types=1);

namespace App\Access;

use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\WorkspaceMember;

/**
 * Effective permission resolution (F10, S19 "Effective permission inspector"):
 *   workspace admin → 'admin' everywhere;
 *   otherwise space membership role;
 *   overridden by the nearest ancestor folder override (or the folder itself);
 *   'none' means no access to that subtree.
 * Returns the role plus the chain that produced it, so the inspector can show
 * the reasoning rather than just the outcome.
 */
final class Access
{
    public const ORDER = ['none' => 0, 'guest' => 1, 'reader' => 2, 'editor' => 3, 'approver' => 4, 'admin' => 5];

    /** @return array{role: ?string, chain: list<array{level: string, id: ?string, name: ?string, role: ?string, note?: string}>} */
    public static function resolve(User $user, Space $space, ?Folder $folder = null): array
    {
        $chain = [];
        /** @var WorkspaceMember|null $wm */
        $wm = WorkspaceMember::query()->where('user_id', $user->getKey())->first();
        $chain[] = ['level' => 'workspace', 'id' => $space->workspace_id, 'name' => null, 'role' => $wm?->role];
        if ($wm === null) {
            return ['role' => null, 'chain' => $chain];
        }
        if ($wm->role === 'admin') {
            $chain[] = ['level' => 'result', 'id' => null, 'name' => null, 'role' => 'admin', 'note' => 'Workspace admins see every space and folder.'];

            return ['role' => 'admin', 'chain' => $chain];
        }

        /** @var SpaceMember|null $sm */
        $sm = SpaceMember::query()->where('space_id', $space->id)->where('user_id', $user->getKey())->first();
        $role = $sm?->role;
        $chain[] = ['level' => 'space', 'id' => $space->id, 'name' => $space->name, 'role' => $role, 'note' => $role ? null : 'Not a member of this space: no access unless a folder grants it.'];

        if ($folder !== null) {
            foreach (self::ancestry($folder) as $f) {
                /** @var FolderPermission|null $fp */
                $fp = FolderPermission::query()->where('folder_id', $f->id)->where('user_id', $user->getKey())->first();
                $chain[] = ['level' => 'folder', 'id' => $f->id, 'name' => $f->name, 'role' => $fp?->role, 'note' => $fp ? 'Override' : 'Inherited'];
                if ($fp !== null) {
                    $role = $fp->role;   // nearest override on the path wins; later (deeper) entries replace earlier ones
                }
            }
        }
        $effective = $role === 'none' ? null : $role;
        $chain[] = ['level' => 'result', 'id' => null, 'name' => null, 'role' => $effective];

        return ['role' => $effective, 'chain' => $chain];
    }

    public static function atLeast(?string $role, string $minimum): bool
    {
        return $role !== null && (self::ORDER[$role] ?? -1) >= (self::ORDER[$minimum] ?? 99);
    }

    /** Root → … → the folder itself. @return list<Folder> */
    public static function ancestry(Folder $folder): array
    {
        $path = [$folder];
        $cur = $folder;
        $guard = 0;
        while ($cur->parent_id !== null && $guard++ < 10) {
            $cur = Folder::query()->find($cur->parent_id);
            if ($cur === null) {
                break;
            }
            array_unshift($path, $cur);
        }

        return $path;
    }

    /**
     * Folder ids in a space the user must NOT see (a 'none' override on the
     * folder or an ancestor) and folder ids they gain via override while not a
     * space member. Used by the document list. @return array{deny: list<string>, grant: list<string>}
     */
    public static function folderOverridesFor(User $user, string $spaceId): array
    {
        $rows = FolderPermission::query()->where('user_id', $user->getKey())
            ->whereIn('folder_id', Folder::query()->where('space_id', $spaceId)->pluck('id'))->get();
        $deny = [];
        $grant = [];
        foreach ($rows as $fp) {
            $subtree = self::subtreeIds($fp->folder_id);
            if ($fp->role === 'none') {
                $deny = array_merge($deny, $subtree);
            } else {
                $grant = array_merge($grant, $subtree);
            }
        }

        return ['deny' => array_values(array_unique($deny)), 'grant' => array_values(array_unique(array_diff($grant, $deny)))];
    }

    /** @return list<string> */
    public static function subtreeIds(string $folderId): array
    {
        $out = [$folderId];
        $frontier = [$folderId];
        while ($frontier) {
            $frontier = Folder::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $out = array_merge($out, $frontier);
        }

        return $out;
    }
}
