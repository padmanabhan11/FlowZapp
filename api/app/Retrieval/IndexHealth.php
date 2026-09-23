<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

/**
 * The doc 04 correctness invariant, checked (G1-T5): every document with a
 * live approved version (Document::live — including one being revised as a
 * draft) has chunks for its approved_version_id with indexed_at set, and
 * nothing else has chunks. Runs inside the current workspace (TenantScope applies).
 */
final class IndexHealth
{
    /**
     * Approved documents whose approved version has no indexed chunks.
     *
     * @return Collection<int, Document>
     */
    public static function unindexed(): Collection
    {
        $docs = Document::query()->live()
            ->with('approvedVersion:id,approved_at')->get(['id', 'title', 'approved_version_id', 'space_id']);
        if ($docs->isEmpty()) {
            return collect();
        }
        $indexed = DocumentChunk::query()->whereIn('version_id', $docs->pluck('approved_version_id'))->whereNotNull('indexed_at')
            ->pluck('version_id')->unique()->flip();

        return $docs->reject(fn (Document $d) => $indexed->has((string) $d->approved_version_id))->values()->toBase();
    }

    /**
     * Documents that have chunks they should not: no live approved version
     * (never approved, archived, deleted) or chunks for a superseded version.
     *
     * @return array{not_approved: list<string>, superseded: list<string>}
     */
    public static function stale(): array
    {
        $chunked = DocumentChunk::query()->get(['document_id', 'version_id'])->groupBy('document_id');
        if ($chunked->isEmpty()) {
            return ['not_approved' => [], 'superseded' => []];
        }
        $docs = Document::query()->whereIn('id', $chunked->keys())->get(['id', 'state', 'approved_version_id'])->keyBy('id');
        $notApproved = [];
        $superseded = [];
        foreach ($chunked as $docId => $rows) {
            $d = $docs->get($docId);
            if ($d === null || $d->state === 'archived' || $d->approved_version_id === null) {
                $notApproved[] = (string) $docId;
            } elseif ($rows->contains(fn (DocumentChunk $c) => $c->version_id !== $d->approved_version_id)) {
                $superseded[] = (string) $docId;
            }
        }

        return ['not_approved' => $notApproved, 'superseded' => $superseded];
    }
}
