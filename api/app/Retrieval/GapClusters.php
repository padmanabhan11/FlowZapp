<?php

declare(strict_types=1);

namespace App\Retrieval;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * FR-614: refused questions grouped by meaning, so "how do I expense a
 * taxi", "taxi receipts – claim?" and "can I claim a cab" count as one gap.
 *
 * Questions are embedded with the workspace's embeddings driver (cached per
 * distinct normalised text, so each question is embedded once) and grouped
 * greedily: a question joins the first group whose founding question is at
 * least flowzapp.chat.gap_similarity similar, else it founds a new group.
 * Exact normalised duplicates always group together, even without embeddings.
 */
final class GapClusters
{
    public function __construct(private readonly Embeddings $emb) {}

    /**
     * @param  list<array{question: string, asked_at: Carbon|null}>  $asked  newest first
     * @return list<array{question: string, count: int, last_asked_at: Carbon|null, variants: list<string>}>
     */
    public function group(array $asked): array
    {
        if ($asked === []) {
            return [];
        }
        $threshold = (float) config('flowzapp.chat.gap_similarity', 0.8);

        $byText = [];   // normalised text → rows
        foreach ($asked as $a) {
            $byText[self::normalise($a['question'])][] = $a;
        }
        $texts = array_keys($byText);
        $vectors = $this->vectors($texts);

        $groups = [];   // each: ['seed' => vector, 'texts' => list<string>]
        foreach ($texts as $t) {
            $v = $vectors[$t] ?? null;
            $placed = false;
            if ($v !== null) {
                foreach ($groups as $gi => $g) {
                    if ($g['seed'] !== null && self::cosine($v, $g['seed']) >= $threshold) {
                        $groups[$gi]['texts'][] = $t;
                        $placed = true;
                        break;
                    }
                }
            }
            if (! $placed) {
                $groups[] = ['seed' => $v, 'texts' => [$t]];
            }
        }

        $out = [];
        foreach ($groups as $g) {
            $rows = array_merge(...array_map(fn ($t) => $byText[$t], $g['texts']));
            // Label a group with its most-asked phrasing, as the asker wrote it.
            $counts = array_map(fn ($t) => count($byText[$t]), $g['texts']);
            $label = $byText[$g['texts'][array_search(max($counts), $counts, true)]][0]['question'];
            $variants = array_values(array_unique(array_map(fn ($t) => $byText[$t][0]['question'], $g['texts'])));
            $last = null;
            foreach ($rows as $r) {
                if ($r['asked_at'] !== null && ($last === null || $r['asked_at']->gt($last))) {
                    $last = $r['asked_at'];
                }
            }
            $out[] = ['question' => $label, 'count' => count($rows), 'last_asked_at' => $last, 'variants' => array_slice($variants, 0, 5)];
        }
        usort($out, fn ($a, $b) => [$b['count'], $b['last_asked_at']?->getTimestamp() ?? 0] <=> [$a['count'], $a['last_asked_at']?->getTimestamp() ?? 0]);

        return $out;
    }

    public static function normalise(string $q): string
    {
        $k = preg_replace('/[^\p{L}\p{N} ]/u', '', mb_strtolower($q)) ?? $q;

        return trim(preg_replace('/\s+/u', ' ', $k) ?? '');
    }

    /**
     * @param  list<string>  $texts
     * @return array<string, list<float>>
     */
    private function vectors(array $texts): array
    {
        $key = fn (string $t) => 'gap-emb:'.$this->emb->model().':'.md5($t);
        $out = [];
        $missing = [];
        foreach ($texts as $t) {
            $v = Cache::get($key($t));
            if (is_array($v)) {
                $out[$t] = $v;
            } else {
                $missing[] = $t;
            }
        }
        if ($missing !== []) {
            try {
                foreach (array_chunk($missing, 100) as $batch) {
                    foreach ($this->emb->embed($batch) as $i => $v) {
                        $out[$batch[$i]] = $v;
                        Cache::put($key($batch[$i]), $v, now()->addDays(30));
                    }
                }
            } catch (\Throwable) {
                // embeddings unavailable: fall back to exact-text grouping for the rest
            }
        }

        return $out;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i] ?? 0.0;
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return $na > 0 && $nb > 0 ? $dot / sqrt($na * $nb) : 0.0;
    }
}
