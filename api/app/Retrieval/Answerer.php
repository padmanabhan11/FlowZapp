<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Ai\Json;
use App\Ai\LlmDriver;
use App\Models\Document;
use App\Models\DocumentChunk;

/**
 * Grounded answering (F-AI "Chatbot on company documents"; FR-606..608):
 * answers only from the retrieved approved chunks, cites each claim by chunk
 * number, and refuses explicitly when the sources do not cover the question.
 * An answer with zero citations is treated as a refusal — never returned as
 * an answer (BRL-05).
 */
final class Answerer
{
    public const SYSTEM = <<<'TXT'
[answer] You answer employees' questions using ONLY the numbered source passages provided, which come from their company's approved procedures.
Rules: every factual statement must be supported by a passage and cite it as [n]; if the passages do not answer the question, set "refused": true and say so plainly — never guess, never use outside knowledge; keep answers short and procedural (steps as a numbered list when the source is a procedure); mention the nearest related passage when refusing, if one exists.
Output JSON only: {"answer":"…","citations":[1,3],"refused":false}
TXT;

    public function __construct(private readonly LlmDriver $llm) {}

    /**
     * @param  list<array{chunk: DocumentChunk, document: Document, score: float, vector_rank: ?int, keyword_rank: ?int}>  $hits
     * @param  list<array{role: string, content: string}>  $history
     * @return array{text: string, refused: bool, citations: list<array<string, mixed>>, cost_usd: float, input_tokens: int, output_tokens: int}
     */
    public function answer(string $question, array $hits, array $history = []): array
    {
        $minScore = (float) config('flowzapp.retrieval.min_score');
        $usable = array_values(array_filter($hits, fn ($h) => $h['score'] >= $minScore || $h['score'] === 0.0 && $h['keyword_rank'] !== null));
        if ($usable === []) {
            return ['text' => 'No approved document covers this yet.', 'refused' => true, 'citations' => [], 'cost_usd' => 0.0, 'input_tokens' => 0, 'output_tokens' => 0];
        }

        $passages = [];
        foreach ($usable as $i => $h) {
            $passages[] = '['.($i + 1)."] ({$h['document']->title} v".($h['document']->approvedVersion?->version_number ?? '?').", {$h['chunk']->section_ref})\n{$h['chunk']->content}";
        }
        $hist = '';
        foreach (array_slice($history, -6) as $m) {
            $hist .= strtoupper($m['role']).": {$m['content']}\n";
        }
        $user = ($hist ? "Conversation so far:\n$hist\n" : '')."Question: $question\n\nSources:\n".implode("\n\n", $passages);
        $res = $this->llm->complete(self::SYSTEM, $user, 1500, 0.0);
        $d = Json::fromText($res['text']) ?? [];

        $refused = (bool) ($d['refused'] ?? true);
        $cited = array_values(array_filter(array_map('intval', is_array($d['citations'] ?? null) ? $d['citations'] : []), fn ($n) => $n >= 1 && $n <= count($usable)));
        if (! $refused && $cited === []) {
            $refused = true; // BRL-05: an answer with no source is not permitted
        }
        $citations = [];
        foreach (array_unique($cited) as $n) {
            $h = $usable[$n - 1];
            $citations[] = ['n' => $n, 'document_id' => $h['document']->id, 'version_id' => $h['chunk']->version_id, 'section_ref' => $h['chunk']->section_ref, 'title' => $h['document']->title, 'heading_path' => $h['chunk']->heading_path, 'score' => round($h['score'], 3)];
        }
        $text = trim((string) ($d['answer'] ?? ''));
        if ($refused) {
            $nearest = $usable[0]['document']->title;
            $text = $text !== '' && str_contains(mb_strtolower($text), 'no approved') ? $text : "No approved document covers this yet. The closest match is \"{$nearest}\", which doesn't answer it.";
            $citations = [];
        }

        return ['text' => $text, 'refused' => $refused, 'citations' => $citations, 'cost_usd' => $res['cost_usd'], 'input_tokens' => $res['input_tokens'], 'output_tokens' => $res['output_tokens']];
    }
}
