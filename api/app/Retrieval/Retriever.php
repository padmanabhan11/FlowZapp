<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Access\Access;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;

/**
 * Hybrid retrieval (03 §6): vector leg + keyword leg, fused by reciprocal
 * rank (position, not raw score — MySQL relevance is not comparable across
 * queries). Permission filtering happens inside both legs, never as a
 * post-filter: the accessible space set and folder overrides are resolved
 * first and passed down (FR-506). Only approved content is ever indexed.
 */
final class Retriever
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly Embeddings $emb, private readonly VectorStore $store) {}

    /** @return array{space_ids: list<string>, deny_folder_ids: list<string>, grant_folder_ids: list<string>} */
    public function scopeFor(User $user, bool $isAdmin): array
    {
        if ($isAdmin) {
            return ['space_ids' => Space::query()->pluck('id')->all(), 'deny_folder_ids' => [], 'grant_folder_ids' => []];
        }
        $member = SpaceMember::query()->where('user_id', $user->id)->pluck('space_id')->all();
        $overrideSpaces = Space::query()->whereIn('id', Folder::query()->whereIn('id', FolderPermission::query()->where('user_id', $user->id)->pluck('folder_id'))->pluck('space_id'))->pluck('id')->all();
        $deny = [];
        $grant = [];
        foreach (array_unique(array_merge($member, $overrideSpaces)) as $sid) {
            $o = Access::folderOverridesFor($user, $sid);
            $deny = array_merge($deny, $o['deny']);
            $grant = array_merge($grant, $o['grant']);
        }

        return ['space_ids' => $member, 'deny_folder_ids' => array_values(array_unique($deny)), 'grant_folder_ids' => array_values(array_unique($grant))];
    }

    /**
     * @param  array{space_ids: list<string>, deny_folder_ids: list<string>, grant_folder_ids: list<string>}  $scope
     * @param  array{space_ids?: list<string>, document_id?: string, doc_type?: string, owner_id?: string}  $filters
     * @return list<array{chunk: DocumentChunk, document: Document, score: float, vector_rank: ?int, keyword_rank: ?int}>
     */
    public function retrieve(string $query, array $scope, array $filters = [], ?int $keep = null): array
    {
        $cfg = config('flowzapp.retrieval');
        $topK = (int) $cfg['top_k'];
        $keep ??= (int) $cfg['keep'];
        $spaceIds = $scope['space_ids'];
        if (! empty($filters['space_ids'])) {
            $spaceIds = array_values(array_intersect($spaceIds, $filters['space_ids']));
            $scope['grant_folder_ids'] = [];   // a space filter narrows to member spaces only
        }
        if ($spaceIds === [] && $scope['grant_folder_ids'] === []) {
            return [];
        }

        // Vector leg — the index filters on workspace, state, space, folder inside the query.
        $vFilter = ['space_ids' => $spaceIds, 'deny_folder_ids' => $scope['deny_folder_ids'], 'grant_folder_ids' => $scope['grant_folder_ids']];
        if (! empty($filters['document_id'])) {
            $vFilter['document_id'] = $filters['document_id'];
        }
        [$qv] = $this->emb->embed([$query]);
        $vector = $this->store->search($this->current->require(), $qv, $vFilter, $topK);

        // Keyword leg — same scope expressed in SQL; LIKE here, FULLTEXT on MySQL is the M5 tuning item (03 §6).
        $terms = array_values(array_filter(preg_split('/\W+/u', mb_strtolower($query)) ?: [], fn ($t) => mb_strlen($t) >= 2));
        $kq = DocumentChunk::query()->whereNotNull('indexed_at')
            ->where(function ($w) use ($spaceIds, $scope): void {
                $w->where(fn ($m) => $m->whereIn('space_id', $spaceIds)->where(fn ($x) => $x->whereNull('folder_id')->orWhereNotIn('folder_id', $scope['deny_folder_ids'])))
                    ->orWhereIn('folder_id', $scope['grant_folder_ids']);
            });
        if (! empty($filters['document_id'])) {
            $kq->where('document_id', $filters['document_id']);
        }
        if ($terms !== []) {
            $kq->where(function ($w) use ($terms): void {
                foreach (array_slice($terms, 0, 8) as $t) {
                    $w->orWhere('content', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%');
                }
            });
        }
        $keyword = $terms === [] ? collect() : $kq->limit($topK)->get();

        // Reciprocal rank fusion, weighted toward the vector leg (03 §6).
        $scores = [];
        $vectorRank = [];
        $keywordRank = [];
        $bestVector = [];
        foreach ($vector as $i => $hit) {
            $scores[$hit['id']] = ($scores[$hit['id']] ?? 0) + 1.0 / (60 + $i + 1);
            $vectorRank[$hit['id']] = $i + 1;
            $bestVector[$hit['id']] = $hit['score'];
        }
        foreach ($keyword as $i => $chunk) {
            $scores[$chunk->id] = ($scores[$chunk->id] ?? 0) + 0.6 / (60 + $i + 1);
            $keywordRank[$chunk->id] = $i + 1;
        }
        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, $keep);
        if ($ids === []) {
            return [];
        }

        $chunks = DocumentChunk::query()->whereIn('id', $ids)->get()->keyBy('id');
        $docQ = Document::query()->whereIn('id', $chunks->pluck('document_id')->unique())->where('state', 'approved')->with('owner:id,name');
        if (! empty($filters['doc_type'])) {
            $docQ->where('doc_type', $filters['doc_type']);
        }
        if (! empty($filters['owner_id'])) {
            $docQ->where('owner_id', $filters['owner_id']);
        }
        $docs = $docQ->get()->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $c = $chunks->get($id);
            if ($c === null || ! $docs->has($c->document_id) || $docs[$c->document_id]->approved_version_id !== $c->version_id) {
                continue; // stale chunk or filtered document
            }
            $out[] = ['chunk' => $c, 'document' => $docs[$c->document_id], 'score' => $bestVector[$id] ?? 0.0, 'vector_rank' => $vectorRank[$id] ?? null, 'keyword_rank' => $keywordRank[$id] ?? null];
        }

        return $out;
    }
}
