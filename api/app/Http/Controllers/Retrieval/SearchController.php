<?php

declare(strict_types=1);

namespace App\Http\Controllers\Retrieval;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Retrieval\Answerer;
use App\Retrieval\Retriever;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /v1/search (05 "Search"; F4; S14). Results grouped by document; instant answer above them when confidence allows. */
final class SearchController extends Controller
{
    public function __construct(private readonly Retriever $retriever, private readonly Answerer $answerer) {}

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:500'],
            'space_ids' => ['nullable', 'array'], 'space_ids.*' => ['string', 'size:26'],
            'filters' => ['nullable', 'array'],
            'filters.doc_type' => ['nullable', Rule::in(Document::TYPES)],
            'filters.owner_id' => ['nullable', 'string', 'size:26'],
            'filters.approved_after' => ['nullable', 'date_format:Y-m-d'],
            'filters.approved_before' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filters.approved_after'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
            'instant' => ['nullable', 'boolean'],
        ]);
        $scope = $this->retriever->scopeFor($request->user(), $request->attributes->get('workspace_role') === 'admin');
        $hits = $this->retriever->retrieve($data['query'], $scope, [
            'space_ids' => $data['space_ids'] ?? [], 'doc_type' => $data['filters']['doc_type'] ?? null, 'owner_id' => $data['filters']['owner_id'] ?? null,
            'approved_after' => $data['filters']['approved_after'] ?? null, 'approved_before' => $data['filters']['approved_before'] ?? null,
        ], $data['limit'] ?? 20);

        // Group by document, best chunk first.
        $byDoc = [];
        foreach ($hits as $h) {
            $id = $h['document']->id;
            if (! isset($byDoc[$id])) {
                $byDoc[$id] = ['document_id' => $id, 'title' => $h['title'], 'doc_type' => $h['document']->doc_type, 'state' => 'approved',   // results are the approved version, even while a draft revision is open
                    'snippet' => mb_substr($h['chunk']->content, 0, 220), 'section_ref' => $h['chunk']->section_ref,
                    'owner' => $h['document']->owner?->only(['id', 'name']), 'approved_at' => $h['document']->approvedVersion?->approved_at, 'score' => round($h['score'], 3)];
            }
        }

        $instant = null;
        if (($data['instant'] ?? true) && $hits !== [] && $hits[0]['score'] >= (float) config('flowzapp.retrieval.min_score')) {
            $a = $this->answerer->answer($data['query'], array_slice($hits, 0, 5));
            if (! $a['refused']) {
                $instant = ['text' => $a['text'], 'citations' => $a['citations']];
            }
        }

        return response()->json(['data' => ['instant_answer' => $instant, 'results' => array_values($byDoc)]]);
    }
}
