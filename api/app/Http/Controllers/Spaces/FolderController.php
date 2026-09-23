<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spaces;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Space;
use App\Retrieval\Deindex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Folders (F3): nestable to depth 5 inside a space. Moving a folder moves its
 * subtree and recomputes depth. Deleting requires an explicit contents
 * strategy — never silent data loss (FR-204): move re-parents child folders
 * and documents to the target; archive archives the documents in the subtree.
 */
final class FolderController extends Controller
{
    /** GET /v1/folders?space_id= — the tree for a space. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['space_id' => ['required', 'string', 'size:26']]);
        $space = Space::query()->findOrFail($data['space_id']);
        $this->authorize('view', $space);

        $folders = Folder::query()->where('space_id', $space->id)->orderBy('position')->orderBy('name')->get();

        return response()->json(['data' => $this->tree($folders, null)]);
    }

    /** POST /v1/folders  body { space_id, name, parent_id? } */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'space_id' => ['required', 'string', 'size:26'],
            'parent_id' => ['nullable', 'string', 'size:26'],
            'name' => ['required', 'string', 'min:1', 'max:120'],
        ]);
        $space = Space::query()->findOrFail($data['space_id']);
        $this->authorize('edit', $space);

        $depth = 0;
        if (! empty($data['parent_id'])) {
            $parent = Folder::query()->where('space_id', $space->id)->findOrFail($data['parent_id']);
            $depth = $parent->depth + 1;
            if ($depth > Folder::MAX_DEPTH) {
                return $this->tooDeep();
            }
        }
        $position = (int) Folder::query()->where('space_id', $space->id)
            ->where('parent_id', $data['parent_id'] ?? null)->max('position') + 1;

        $folder = Folder::create([
            'space_id' => $space->id, 'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'], 'depth' => $depth, 'position' => $position,
        ]);
        Audit::record('folder.created', 'folder', $folder->id, ['name' => $folder->name, 'space_id' => $space->id]);

        return response()->json(['data' => $this->present($folder)], 201);
    }

    /** PATCH /v1/folders/{id}  body { name?, parent_id?, position? } — rename, move, reorder. */
    public function update(Request $request, string $id): JsonResponse
    {
        $folder = Folder::query()->findOrFail($id);
        $space = Space::query()->findOrFail($folder->space_id);
        $this->authorize('edit', $space);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($folder, $data, $space): void {
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== $folder->parent_id) {
                $newDepth = 0;
                if ($data['parent_id'] !== null) {
                    $parent = Folder::query()->where('space_id', $space->id)->findOrFail($data['parent_id']);
                    abort_if($parent->id === $folder->id || $this->isDescendant($parent, $folder), 422, 'A folder cannot be moved inside itself.');
                    $newDepth = $parent->depth + 1;
                }
                $subtreeHeight = $this->subtreeHeight($folder);
                abort_if($newDepth + $subtreeHeight > Folder::MAX_DEPTH, 422, 'Folders can be nested at most '.Folder::MAX_DEPTH.' levels deep.');
                $this->reparent($folder, $data['parent_id'], $newDepth);
                Audit::record('folder.moved', 'folder', $folder->id, ['to_parent' => $data['parent_id']]);
            }
            if (isset($data['name'])) {
                $folder->name = $data['name'];
            }
            if (isset($data['position'])) {
                $folder->position = $data['position'];
            }
            $folder->save();
        });

        return response()->json(['data' => $this->present($folder->fresh())]);
    }

    /**
     * DELETE /v1/folders/{id}?strategy=archive|move&target_folder_id=
     * The caller must choose what happens to the contents (FR-204).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $folder = Folder::query()->findOrFail($id);
        $space = Space::query()->findOrFail($folder->space_id);
        $this->authorize('approve', $space); // Admin/Approver per S6

        $data = $request->validate([
            'strategy' => ['required', Rule::in(['archive', 'move'])],
            'target_folder_id' => ['required_if:strategy,move', 'nullable', 'string', 'size:26'],
        ]);

        DB::transaction(function () use ($folder, $data, $space): void {
            $children = Folder::query()->where('parent_id', $folder->id)->get();
            if ($data['strategy'] === 'move') {
                $targetId = $data['target_folder_id'] ?: null;
                $targetDepth = 0;
                if ($targetId !== null) {
                    $target = Folder::query()->where('space_id', $space->id)->findOrFail($targetId);
                    abort_if($target->id === $folder->id || $this->isDescendant($target, $folder), 422, 'Cannot move contents into the folder being deleted.');
                    $targetDepth = $target->depth + 1;
                }
                foreach ($children as $child) {
                    abort_if($targetDepth + $this->subtreeHeight($child) > Folder::MAX_DEPTH, 422, 'Moving the contents would exceed the maximum folder depth.');
                    $this->reparent($child, $targetId, $targetDepth);
                }
                Document::query()->where('folder_id', $folder->id)->update(['folder_id' => $targetId]);
            } else {
                // archive: documents in the subtree are archived (state) and detached; child folders cascade.
                $ids = $this->descendants($folder)->pluck('id')->push($folder->id);
                foreach (Document::query()->whereIn('folder_id', $ids)->pluck('id') as $docId) {
                    Deindex::document($space->workspace_id, (string) $docId);
                }
                Document::query()->whereIn('folder_id', $ids)->update(['state' => 'archived', 'folder_id' => null]);
            }
            Audit::record('folder.deleted', 'folder', $folder->id, ['strategy' => $data['strategy'], 'name' => $folder->name]);
            $folder->delete();
        });

        return response()->json(['data' => ['id' => $id, 'deleted' => true, 'strategy' => $data['strategy']]]);
    }

    private function reparent(Folder $folder, ?string $parentId, int $newDepth): void
    {
        $delta = $newDepth - $folder->depth;
        $folder->parent_id = $parentId;
        $folder->depth = $newDepth;
        $folder->save();
        if ($delta !== 0) {
            foreach ($this->descendants($folder) as $d) {
                $d->depth += $delta;
                $d->save();
            }
        }
    }

    /**
     * @return Collection<int, Folder>
     */
    private function descendants(Folder $folder): Collection
    {
        $out = [];
        $frontier = [$folder->id];
        while ($frontier) {
            $rows = Folder::query()->whereIn('parent_id', $frontier)->get();
            array_push($out, ...$rows->all());
            $frontier = $rows->pluck('id')->all();
        }

        return collect($out);
    }

    private function isDescendant(Folder $candidate, Folder $of): bool
    {
        return $this->descendants($of)->contains('id', $candidate->id);
    }

    /** Levels below this folder (0 = leaf). */
    private function subtreeHeight(Folder $folder): int
    {
        $max = $this->descendants($folder)->max('depth');

        return $max === null ? 0 : (int) $max - $folder->depth;
    }

    /**
     * @param  Collection<int, Folder>  $all
     * @return list<array<string, mixed>>
     */
    private function tree(Collection $all, ?string $parentId): array
    {
        return $all->where('parent_id', $parentId)->values()
            ->map(fn (Folder $f) => $this->present($f) + ['children' => $this->tree($all, $f->id)])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Folder $f): array
    {
        return $f->only(['id', 'space_id', 'parent_id', 'name', 'position', 'depth']);
    }

    private function tooDeep(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'folder_too_deep', 'message' => 'Folders can be nested at most '.Folder::MAX_DEPTH.' levels deep.']], 422);
    }
}
