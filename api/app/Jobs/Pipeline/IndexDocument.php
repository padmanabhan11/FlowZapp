<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Retrieval\Chunker;
use App\Retrieval\Embeddings;
use App\Retrieval\VectorStore;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Stage 5 — chunk & embed, on approval only (03 §5). Replaces the document's
 * previous chunks and vectors; runs on the `index` queue so a 40-minute
 * transcription never delays it. Correctness invariant (doc 04): every
 * approved document has chunks for its approved_version_id with indexed_at set.
 */
final class IndexDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 20;

    public function __construct(public string $workspaceId, public string $documentId, public string $versionId)
    {
        $this->onQueue('index');
    }

    public function handle(CurrentWorkspace $current, Embeddings $emb, VectorStore $store): void
    {
        $current->runAs($this->workspaceId, function () use ($emb, $store): void {
            $doc = Document::query()->find($this->documentId);
            $version = DocumentVersion::query()->find($this->versionId);
            if ($doc === null || $version === null || $doc->approved_version_id !== $version->id || $doc->state !== 'approved') {
                return; // superseded while queued
            }
            $pieces = Chunker::chunks($version);
            $vectors = $emb->embed(array_column($pieces, 'content'));

            DB::transaction(function () use ($doc, $version, $pieces, $vectors, $emb, $store): void {
                $store->deleteByDocument($this->workspaceId, $doc->id);
                DocumentChunk::query()->where('document_id', $doc->id)->delete();
                $points = [];
                foreach ($pieces as $i => $p) {
                    $chunk = DocumentChunk::create($p + [
                        'space_id' => $doc->space_id, 'document_id' => $doc->id, 'version_id' => $version->id, 'folder_id' => $doc->folder_id,
                        'token_count' => (int) ceil(str_word_count($p['content']) * 1.3), 'embed_model' => $emb->model(), 'indexed_at' => now(),
                    ]);
                    $chunk->forceFill(['vector_id' => $chunk->id])->save();
                    $points[] = ['id' => $chunk->id, 'vector' => $vectors[$i], 'payload' => [
                        'space_id' => $doc->space_id, 'folder_id' => $doc->folder_id, 'document_id' => $doc->id, 'version_id' => $version->id,
                        'section_ref' => $p['section_ref'], 'state' => 'approved',
                    ]];
                }
                $store->upsert($this->workspaceId, $points);
            });
        });
    }
}
