<?php

declare(strict_types=1);

namespace App\Retrieval;

use Illuminate\Support\Facades\Http;

/**
 * Qdrant (decision 21 Sep 2026): one collection per environment, workspace
 * isolation by an indexed `workspace_id` payload field plus the `must`
 * filter on every query — the independent second check from 03 §4.3.
 */
final class QdrantStore implements VectorStore
{
    public function __construct(private readonly string $baseUrl, private readonly ?string $apiKey, private readonly string $collection, private readonly int $dims) {}

    public function ensureCollection(): void
    {
        $exists = $this->http()->get("/collections/{$this->collection}")->successful();
        if (! $exists) {
            $this->http()->put("/collections/{$this->collection}", ['vectors' => ['size' => $this->dims, 'distance' => 'Cosine']])->throw();
            foreach (['workspace_id', 'space_id', 'folder_id', 'document_id', 'state'] as $field) {
                $this->http()->put("/collections/{$this->collection}/index", ['field_name' => $field, 'field_schema' => 'keyword'])->throw();
            }
        }
    }

    public function upsert(string $workspaceId, array $points): void
    {
        $this->http()->put("/collections/{$this->collection}/points?wait=true", ['points' => array_map(fn ($p) => [
            'id' => $this->pointId($p['id']), 'vector' => $p['vector'], 'payload' => $p['payload'] + ['workspace_id' => $workspaceId, 'chunk_id' => $p['id']],
        ], $points)])->throw();
    }

    public function delete(string $workspaceId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->http()->post("/collections/{$this->collection}/points/delete?wait=true", ['filter' => ['must' => [
            ['key' => 'workspace_id', 'match' => ['value' => $workspaceId]],
            ['key' => 'chunk_id', 'match' => ['any' => array_values($ids)]],
        ]]])->throw();
    }

    public function deleteByDocument(string $workspaceId, string $documentId): void
    {
        $this->http()->post("/collections/{$this->collection}/points/delete?wait=true", ['filter' => ['must' => [
            ['key' => 'workspace_id', 'match' => ['value' => $workspaceId]],
            ['key' => 'document_id', 'match' => ['value' => $documentId]],
        ]]])->throw();
    }

    public function search(string $workspaceId, array $vector, array $filter, int $topK = 20): array
    {
        $must = [
            ['key' => 'workspace_id', 'match' => ['value' => $workspaceId]],
            ['key' => 'state', 'match' => ['value' => 'approved']],
        ];
        $should = [['key' => 'space_id', 'match' => ['any' => $filter['space_ids']]]];
        if (! empty($filter['grant_folder_ids'])) {
            $should[] = ['key' => 'folder_id', 'match' => ['any' => $filter['grant_folder_ids']]];
        }
        $mustNot = [];
        if (! empty($filter['deny_folder_ids'])) {
            $mustNot[] = ['key' => 'folder_id', 'match' => ['any' => $filter['deny_folder_ids']]];
        }
        if (! empty($filter['document_id'])) {
            $must[] = ['key' => 'document_id', 'match' => ['value' => $filter['document_id']]];
        }
        $res = $this->http()->post("/collections/{$this->collection}/points/search", [
            'vector' => $vector, 'limit' => $topK, 'with_payload' => true,
            'filter' => ['must' => $must, 'should' => $should, 'must_not' => $mustNot],
        ])->throw()->json();

        return array_map(fn ($r) => ['id' => (string) ($r['payload']['chunk_id'] ?? $r['id']), 'score' => (float) $r['score'], 'payload' => $r['payload'] ?? []], $res['result'] ?? []);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::baseUrl(rtrim($this->baseUrl, '/'))->timeout(30);

        return $this->apiKey ? $req->withHeaders(['api-key' => $this->apiKey]) : $req;
    }

    /** Qdrant point ids must be UUIDs or integers; derive a UUID from the ULID deterministically. */
    private function pointId(string $chunkId): string
    {
        $h = md5($chunkId);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }
}
