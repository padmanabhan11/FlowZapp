<?php

declare(strict_types=1);

namespace App\Retrieval;

/**
 * Vector index behind a driver (03 §4.3): partitioned by workspace, with
 * workspace_id, space_id, folder_id and state in every vector's payload so
 * retrieval filters inside the query and never post-filters.
 */
interface VectorStore
{
    /**
     * @param  list<array{id: string, vector: list<float>, payload: array<string, mixed>}>  $points
     */
    public function upsert(string $workspaceId, array $points): void;

    /**
     * @param  list<string>  $ids
     */
    public function delete(string $workspaceId, array $ids): void;

    /** Remove every vector for a document (by payload filter). */
    public function deleteByDocument(string $workspaceId, string $documentId): void;

    /**
     * @param  list<float>  $vector
     * @param  array{space_ids: list<string>, deny_folder_ids?: list<string>, grant_folder_ids?: list<string>, document_id?: string, document_ids?: list<string>}  $filter  document_ids: restrict to this document set (search filters)
     * @return list<array{id: string, score: float, payload: array<string, mixed>}>
     */
    public function search(string $workspaceId, array $vector, array $filter, int $topK = 20): array;
}
