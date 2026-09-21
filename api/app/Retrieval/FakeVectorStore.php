<?php

declare(strict_types=1);

namespace App\Retrieval;

/** In-memory cosine search with the same payload filter semantics as Qdrant, for tests. */
final class FakeVectorStore implements VectorStore
{
    /** @var array<string, array<string, array{vector: list<float>, payload: array<string, mixed>}>> workspace → id → point */
    public array $points = [];

    public function upsert(string $workspaceId, array $points): void
    {
        foreach ($points as $p) {
            $this->points[$workspaceId][$p['id']] = ['vector' => $p['vector'], 'payload' => $p['payload'] + ['workspace_id' => $workspaceId]];
        }
    }

    public function delete(string $workspaceId, array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->points[$workspaceId][$id]);
        }
    }

    public function deleteByDocument(string $workspaceId, string $documentId): void
    {
        foreach ($this->points[$workspaceId] ?? [] as $id => $p) {
            if (($p['payload']['document_id'] ?? null) === $documentId) {
                unset($this->points[$workspaceId][$id]);
            }
        }
    }

    public function search(string $workspaceId, array $vector, array $filter, int $topK = 20): array
    {
        $out = [];
        foreach ($this->points[$workspaceId] ?? [] as $id => $p) {
            $pl = $p['payload'];
            if (($pl['state'] ?? null) !== 'approved') {
                continue;
            }
            if (! empty($filter['document_id']) && ($pl['document_id'] ?? null) !== $filter['document_id']) {
                continue;
            }
            $inSpace = in_array($pl['space_id'] ?? null, $filter['space_ids'], true);
            $granted = ! empty($filter['grant_folder_ids']) && in_array($pl['folder_id'] ?? null, $filter['grant_folder_ids'], true);
            $denied = ! empty($filter['deny_folder_ids']) && in_array($pl['folder_id'] ?? null, $filter['deny_folder_ids'], true);
            if ((! $inSpace && ! $granted) || $denied) {
                continue;
            }
            $dot = 0.0;
            foreach ($vector as $i => $x) {
                $dot += $x * ($p['vector'][$i] ?? 0.0);
            }
            $out[] = ['id' => $id, 'score' => $dot, 'payload' => $pl];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, $topK);
    }
}
