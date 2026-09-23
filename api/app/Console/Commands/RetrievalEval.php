<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Retrieval\Answerer;
use App\Retrieval\Retriever;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

/**
 * Retrieval eval harness (G2-T3 relevance, G3-T3 ranking; doc 08 "Retrieval
 * eval set"). Runs a fixed query set through the product Retriever, as a real
 * member of a real workspace, under several fusion settings, and reports:
 *
 *   - correct document in top 1 / top 3 (doc 08 target: top 3 > 90%) and MRR;
 *   - the right step or section in the top 5 where the query names it;
 *   - refusals: correct refusal on unanswerable queries (> 95%) and false refusal
 *     on answerable ones (< 5%), at search level (top vector score below
 *     retrieval.min_score) and, with --answer, from the assistant itself, which
 *     also checks every non-refused answer carries a citation (100%).
 *
 * The set is JSON (see spike/retrieval-eval/README.md):
 *   {"queries": [
 *     {"q": "how do I refund an order after 30 days", "expect": ["Refund Policy"], "section": "step:2"},
 *     {"q": "what is the CEO's salary", "unanswerable": true}
 *   ]}
 * "expect" holds document titles or ids; any of them counts as a hit.
 */
final class RetrievalEval extends Command
{
    protected $signature = 'retrieval:eval
        {set : path to the query set JSON}
        {--workspace= : workspace id or slug}
        {--as= : email of the member to search as (their permissions apply)}
        {--weights=1:0.6,1:0,0:1,1:0.3,1:1 : fusion settings to compare, vector:keyword}
        {--answer : also run the assistant on every query for the first setting (calls the LLM)}
        {--out=storage/app/retrieval-eval : output folder}';

    protected $description = 'Score search relevance and ranking against a fixed query set';

    public function handle(CurrentWorkspace $current, Retriever $retriever, Answerer $answerer): int
    {
        $set = json_decode((string) @file_get_contents((string) $this->argument('set')), true);
        if (! is_array($set) || ! is_array($set['queries'] ?? null) || $set['queries'] === []) {
            $this->error('the set must be JSON with a non-empty "queries" array');

            return self::FAILURE;
        }
        $wsKey = (string) $this->option('workspace');
        $ws = Workspace::query()->where('id', $wsKey)->orWhere('slug', $wsKey)->first();
        $user = User::query()->where('email', (string) $this->option('as'))->first();
        if ($ws === null || $user === null) {
            $this->error('--workspace and --as must name an existing workspace and user');

            return self::FAILURE;
        }
        $settings = [];
        foreach (explode(',', (string) $this->option('weights')) as $pair) {
            [$v, $k] = array_map('floatval', explode(':', $pair) + [1 => 0]);
            $settings[] = ['label' => "v{$v}:k{$k}", 'vector_weight' => $v, 'keyword_weight' => $k, 'legs' => $v == 0.0 ? 'keyword' : ($k == 0.0 ? 'vector' : 'both')];
        }
        $out = rtrim((string) $this->option('out'), '/');
        @mkdir($out, 0775, true);
        $minScore = (float) config('flowzapp.retrieval.min_score');

        $report = $current->runAs($ws->id, function () use ($set, $user, $settings, $retriever, $answerer, $minScore): array {
            $isAdmin = WorkspaceMember::query()->where('user_id', $user->id)->value('role') === 'admin';
            $scope = $retriever->scopeFor($user, $isAdmin);
            $rows = [];
            $summary = [];
            foreach ($settings as $s) {
                $m = ['answerable' => 0, 'hit1' => 0, 'hit3' => 0, 'rr' => 0.0, 'section_total' => 0, 'section_hit5' => 0, 'search_false_refusal' => 0,
                    'unanswerable' => 0, 'search_refused' => 0, 'ans_unanswerable' => 0, 'ans_refused' => 0, 'ans_answerable' => 0, 'ans_false_refusal' => 0, 'ans_answered' => 0, 'ans_cited' => 0];
                $withAnswer = $this->option('answer') && $s === $settings[0];
                foreach ($set['queries'] as $q) {
                    if (! is_array($q) || trim((string) ($q['q'] ?? '')) === '') {
                        continue;
                    }
                    $hits = $retriever->retrieve((string) $q['q'], $scope, [], 20, $s);
                    $docs = [];
                    foreach ($hits as $h) {
                        $docs[$h['document']->id] ??= ['title' => $h['document']->title, 'sections' => []];
                        $docs[$h['document']->id]['sections'][] = $h['chunk']->section_ref;
                    }
                    $ranked = array_keys($docs);
                    $row = ['setting' => $s['label'], 'query' => $q['q'], 'top' => implode(' | ', array_slice(array_map(fn ($id) => $docs[$id]['title'], $ranked), 0, 5))];

                    $topScore = max(array_map(fn ($h) => $h['score'], $hits) ?: [0.0]);
                    $searchRefuses = $hits === [] || $topScore < $minScore;
                    $row += ['search_refused' => $searchRefuses ? 'yes' : 'no', 'top_score' => round($topScore, 3)];
                    $a = $withAnswer ? $answerer->answer((string) $q['q'], array_slice($hits, 0, 5)) : null;
                    if ($a !== null) {
                        $row['assistant_refused'] = $a['refused'] ? 'yes' : 'no';
                    }

                    if (! empty($q['unanswerable'])) {
                        $m['unanswerable']++;
                        $m['search_refused'] += (int) $searchRefuses;
                        if ($a !== null) {
                            $m['ans_unanswerable']++;
                            $m['ans_refused'] += (int) $a['refused'];
                        }
                        $rows[] = $row + ['kind' => 'unanswerable', 'rank' => ''];

                        continue;
                    }
                    $m['search_false_refusal'] += (int) $searchRefuses;
                    if ($a !== null) {
                        $m['ans_answerable']++;
                        $m['ans_false_refusal'] += (int) $a['refused'];
                        if (! $a['refused']) {
                            $m['ans_answered']++;
                            $m['ans_cited'] += (int) ($a['citations'] !== []);
                        }
                    }

                    $expect = array_map('strval', (array) ($q['expect'] ?? []));
                    $rank = null;
                    foreach ($ranked as $i => $id) {
                        if (in_array($id, $expect, true) || in_array($docs[$id]['title'], $expect, true)) {
                            $rank = $i + 1;
                            break;
                        }
                    }
                    $m['answerable']++;
                    $m['hit1'] += (int) ($rank === 1);
                    $m['hit3'] += (int) ($rank !== null && $rank <= 3);
                    $m['rr'] += $rank ? 1 / $rank : 0;
                    $sectionHit = '';
                    if (! empty($q['section'])) {
                        $m['section_total']++;
                        $top5 = array_slice(array_map(fn ($h) => $h['chunk']->section_ref, $hits), 0, 5);
                        $ok = in_array((string) $q['section'], $top5, true);
                        $m['section_hit5'] += (int) $ok;
                        $sectionHit = $ok ? 'yes' : 'no';
                    }
                    $rows[] = $row + ['kind' => 'answerable', 'rank' => $rank ?? 'miss', 'section_hit5' => $sectionHit];
                }
                $summary[$s['label']] = $m;
            }

            return ['rows' => $rows, 'summary' => $summary];
        });

        $this->writeCsv("{$out}/queries.csv", $report['rows']);
        $md = $this->markdown($report['summary'], $minScore);
        file_put_contents("{$out}/summary.md", $md);
        $this->line($md);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, int|float>>  $summary
     */
    private function markdown(array $summary, float $minScore): string
    {
        $pct = fn ($n, $d) => $d ? sprintf('%d%%', round(100 * $n / $d)) : '—';
        $labels = array_keys($summary);
        $l = ['# Retrieval eval — summary', '', 'Targets from 08-QA-and-Test-Plan (retrieval eval set). Fusion settings are vector:keyword weights; the live setting is retrieval.vector_weight / keyword_weight.', '',
            '| Metric | Target | '.implode(' | ', $labels).' |', '|---|---|'.str_repeat('---|', count($labels))];
        $row = function (string $label, string $target, callable $f) use (&$l, $summary): void {
            $l[] = "| {$label} | {$target} | ".implode(' | ', array_map($f, $summary)).' |';
        };
        $row('Answerable queries', '50', fn ($m) => (string) $m['answerable']);
        $row('Correct document first', 'track; must not drop', fn ($m) => $pct($m['hit1'], $m['answerable']));
        $row('Correct document in top 3', '> 90%', fn ($m) => $pct($m['hit3'], $m['answerable']));
        $row('MRR', 'track; must not drop', fn ($m) => $m['answerable'] ? sprintf('%.2f', $m['rr'] / $m['answerable']) : '—');
        $row('Right step/section in top 5', 'track', fn ($m) => $pct($m['section_hit5'], $m['section_total']));
        $row('Unanswerable queries', '15', fn ($m) => (string) $m['unanswerable']);
        $row("Search: correct refusal (top score < {$minScore})", '> 95%', fn ($m) => $pct($m['search_refused'], $m['unanswerable']));
        $row('Search: false refusal on answerable', '< 5%', fn ($m) => $pct($m['search_false_refusal'], $m['answerable']));
        $row('Assistant: correct refusal (--answer)', '> 95%', fn ($m) => $pct($m['ans_refused'], $m['ans_unanswerable']));
        $row('Assistant: false refusal (--answer)', '< 5%', fn ($m) => $pct($m['ans_false_refusal'], $m['ans_answerable']));
        $row('Assistant: citations on every answer (--answer)', '100%', fn ($m) => $pct($m['ans_cited'], $m['ans_answered']));

        return implode("\n", $l)."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $cols = ['setting', 'kind', 'query', 'rank', 'section_hit5', 'search_refused', 'top_score', 'assistant_refused', 'top'];
        $fh = fopen($path, 'w');
        if ($fh === false) {
            return;
        }
        fputcsv($fh, $cols, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn ($c) => (string) ($r[$c] ?? ''), $cols), ',', '"', '');
        }
        fclose($fh);
    }
}
