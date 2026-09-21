<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Models\DocumentChunk;

/** Removes a document from retrieval in the same transaction as the state change (03 §6 "Re-indexing"; FR-615). */
final class Deindex
{
    public static function document(string $workspaceId, string $documentId): void
    {
        app(VectorStore::class)->deleteByDocument($workspaceId, $documentId);
        DocumentChunk::query()->where('document_id', $documentId)->delete();
    }

    /** A moved document keeps its chunks but the payload scope must follow it: re-index. */
    public static function rescope(string $workspaceId, string $documentId, string $spaceId, ?string $folderId): void
    {
        $chunks = DocumentChunk::query()->where('document_id', $documentId)->get();
        if ($chunks->isEmpty()) {
            return;
        }
        $chunks->each(fn (DocumentChunk $c) => $c->forceFill(['space_id' => $spaceId, 'folder_id' => $folderId])->save());
        $emb = app(Embeddings::class);
        $vectors = $emb->embed($chunks->pluck('content')->all());
        $store = app(VectorStore::class);
        $store->deleteByDocument($workspaceId, $documentId);
        $points = [];
        foreach ($chunks->values() as $i => $c) {
            $points[] = ['id' => $c->id, 'vector' => $vectors[$i], 'payload' => ['space_id' => $spaceId, 'folder_id' => $folderId, 'document_id' => $documentId, 'version_id' => $c->version_id, 'section_ref' => $c->section_ref, 'state' => 'approved']];
        }
        $store->upsert($workspaceId, $points);
    }
}
