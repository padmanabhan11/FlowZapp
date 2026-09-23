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
use Illuminate\Support\Carbon;

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

    /**
     * @return array{space_ids: list<string>, deny_folder_ids: list<string>, grant_folder_ids: list<string>}
     */
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
     * @param  array{space_ids?: list<string>, document_id?: ?string, doc_type?: ?string, owner_id?: ?string, approved_after?: ?string, approved_before?: ?string}  $filters
     * @param  array{vector_weight?: float, keyword_weight?: float, legs?: 'both'|'vector'|'keyword'}  $tuning  eval harness only (G3-T3)
     * @return list<array{chunk: DocumentChunk, document: Document, title: string, score: float, vector_rank: ?int, keyword_rank: ?int}> title is the approved version's title (the working copy may be a draft)
     */
    public function retrieve(string $query, array $scope, array $filters = [], ?int $keep = null, array $tuning = []): array
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

        // Document-level filters (type, owner, approval date) resolve to a document set first,
        // which both legs then filter on inside their queries (G4, FR-506: never a post-filter
        // that silently shrinks the result list).
        $docIds = $this->filteredDocumentIds($filters);
        if ($docIds === []) {
            return [];
        }
        $legs = $tuning['legs'] ?? 'both';

        // Vector leg — the index filters on workspace, state, space, folder (and document set) inside the query.
        $vector = [];
        if ($legs !== 'keyword') {
            $vFilter = ['space_ids' => $spaceIds, 'deny_folder_ids' => $scope['deny_folder_ids'], 'grant_folder_ids' => $scope['grant_folder_ids']];
            if (! empty($filters['document_id'])) {
                $vFilter['document_id'] = $filters['document_id'];
            }
            if ($docIds !== null) {
                $vFilter['document_ids'] = $docIds;
            }
            [$qv] = $this->emb->embed([$query]);
            $vector = $this->store->search($this->current->require(), $qv, $vFilter, $topK);
        }

        // Keyword leg — same scope in SQL: InnoDB FULLTEXT on MySQL (G3-T1), LIKE elsewhere.
        $keyword = $legs === 'vector' ? [] : $this->keyword($query, $spaceIds, $scope, $filters['document_id'] ?? null, $docIds, $topK);

        // Reciprocal rank fusion by position, weighted toward the vector leg (03 §6).
        $wv = (float) ($tuning['vector_weight'] ?? $cfg['vector_weight'] ?? 1.0);
        $wk = (float) ($tuning['keyword_weight'] ?? $cfg['keyword_weight'] ?? 0.6);
        $scores = [];
        $vectorRank = [];
        $keywordRank = [];
        $bestVector = [];
        foreach ($vector as $i => $hit) {
            $scores[$hit['id']] = ($scores[$hit['id']] ?? 0) + $wv / (60 + $i + 1);
            $vectorRank[$hit['id']] = $i + 1;
            $bestVector[$hit['id']] = $hit['score'];
        }
        foreach ($keyword as $i => $chunk) {
            $scores[$chunk->id] = ($scores[$chunk->id] ?? 0) + $wk / (60 + $i + 1);
            $keywordRank[$chunk->id] = $i + 1;
        }
        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, $keep);
        if ($ids === []) {
            return [];
        }

        $chunks = DocumentChunk::query()->whereIn('id', $ids)->get()->keyBy('id');
        $docs = Document::query()->whereIn('id', $chunks->pluck('document_id')->unique())->live()->with(['owner:id,name', 'approvedVersion:id,version_number,title,approved_at'])->get()->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $c = $chunks->get($id);
            if ($c === null || ! $docs->has($c->document_id) || $docs[$c->document_id]->approved_version_id !== $c->version_id) {
                continue; // stale chunk (superseded version) — the unindexed monitor catches the rest
            }
            $out[] = ['chunk' => $c, 'document' => $docs[$c->document_id], 'title' => $docs[$c->document_id]->approvedVersion->title ?? $docs[$c->document_id]->title, 'score' => $bestVector[$id] ?? 0.0, 'vector_rank' => $vectorRank[$id] ?? null, 'keyword_rank' => $keywordRank[$id] ?? null];
        }

        return $out;
    }

    /**
     * Live documents matching the document-level filters, or null when none is set.
     *
     * @param  array{doc_type?: ?string, owner_id?: ?string, approved_after?: ?string, approved_before?: ?string}  $filters
     * @return list<string>|null
     */
    private function filteredDocumentIds(array $filters): ?array
    {
        $type = $filters['doc_type'] ?? null;
        $owner = $filters['owner_id'] ?? null;
        $after = $filters['approved_after'] ?? null;
        $before = $filters['approved_before'] ?? null;
        if (! $type && ! $owner && ! $after && ! $before) {
            return null;
        }
        $q = Document::query()->live();
        if ($type) {
            $q->where('doc_type', $type);
        }
        if ($owner) {
            $q->where('owner_id', $owner);
        }
        if ($after || $before) {
            $q->whereHas('approvedVersion', function ($v) use ($after, $before): void {
                if ($after) {
                    $v->where('approved_at', '>=', Carbon::parse($after)->startOfDay());
                }
                if ($before) {
                    $v->where('approved_at', '<=', Carbon::parse($before)->endOfDay());
                }
            });
        }

        return $q->limit(5000)->pluck('id')->all();
    }

    /**
     * @param  list<string>  $spaceIds
     * @param  array{space_ids: list<string>, deny_folder_ids: list<string>, grant_folder_ids: list<string>}  $scope
     * @param  list<string>|null  $docIds
     * @return list<DocumentChunk>
     */
    private function keyword(string $query, array $spaceIds, array $scope, ?string $documentId, ?array $docIds, int $topK): array
    {
        $terms = Stopwords::terms($query);
        if ($terms === []) {
            return [];
        }
        $kq = DocumentChunk::query()->whereNotNull('indexed_at')
            ->where(function ($w) use ($spaceIds, $scope): void {
                $w->where(fn ($m) => $m->whereIn('space_id', $spaceIds)->where(fn ($x) => $x->whereNull('folder_id')->orWhereNotIn('folder_id', $scope['deny_folder_ids'])))
                    ->orWhereIn('folder_id', $scope['grant_folder_ids']);
            });
        if ($documentId) {
            $kq->where('document_id', $documentId);
        }
        if ($docIds !== null) {
            $kq->whereIn('document_id', $docIds);
        }

        if ($kq->getModel()->getConnection()->getDriverName() === 'mysql') {
            $against = implode(' ', $terms);
            $kq->whereFullText(['content', 'heading_path'], $against)
                ->orderByRaw('match (content, heading_path) against (? in natural language mode) desc', [$against]); // allowlisted: FULLTEXT relevance ordering, bound parameter, scope applied above

            return $kq->limit($topK)->get()->all();
        }

        // LIKE fallback (SQLite): candidates matching any term, ranked by how many distinct terms they contain.
        $terms = array_slice($terms, 0, 8);
        $kq->where(function ($w) use ($terms): void {
            foreach ($terms as $t) {
                $w->orWhere('content', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%');
            }
        });
        $candidates = $kq->limit(200)->get()->all();
        $rank = [];
        foreach ($candidates as $i => $c) {
            $text = mb_strtolower($c->content);
            $hits = count(array_filter($terms, fn ($t) => str_contains($text, $t)));
            $rank[$i] = [$hits, -$i];
        }
        uksort($rank, fn ($a, $b) => $rank[$b] <=> $rank[$a]);

        return array_slice(array_map(fn ($i) => $candidates[$i], array_keys($rank)), 0, $topK);
    }
}
