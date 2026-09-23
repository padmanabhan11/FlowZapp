<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Ai\Json;
use App\Ai\LlmDriver;
use App\Ai\Prompts;

/**
 * Stage 4 model work (03 §5), shared by the GenerateDraft job and the
 * pipeline:eval harness. Returns the validated draft; turning it into a
 * document is the job's business.
 *
 * D4-T2 cost control: a short recording goes to the model whole. A long one
 * (transcript lines over flowzapp.pipeline.digest_over_chars) is first
 * condensed segment by segment, in batches, and generation reads the digests
 * instead of the raw words. Digest batches that fail fall back to the raw
 * text for those segments, so a flaky digest never loses narration.
 */
final class DraftWriter
{
    public function __construct(private readonly LlmDriver $llm) {}

    /**
     * @param  list<array{position:int,ts_start:float,ts_end:float,summary:?string}>  $segments
     * @param  list<array{w:string,start:float,end:float}>  $words
     * @return array{draft: array<string,mixed>, cost_usd: float, condensed: bool, calls: int}
     *
     * @throws PipelineFailed on unusable model output (no partial drafts)
     */
    public function write(string $title, array $segments, array $words): array
    {
        $lines = Prompts::transcriptLines($words);
        $cost = 0.0;
        $calls = 0;
        $condensed = false;
        if (mb_strlen($lines) > (int) config('flowzapp.pipeline.digest_over_chars', 24000)) {
            [$lines, $digestCost, $digestCalls] = $this->digest($segments, $words);
            $cost += $digestCost;
            $calls += $digestCalls;
            $condensed = true;
        }

        $res = $this->llm->complete(Prompts::GENERATE_SYSTEM, Prompts::generateUser($title, $segments, $lines, $condensed), 8192);
        $cost += (float) $res['cost_usd'];
        $calls++;

        $d = Json::fromText($res['text']);
        if (! is_array($d) || empty($d['steps']) || ! is_array($d['steps'])) {
            throw new PipelineFailed('A draft could not be generated from this recording. Try again, or record it with clearer narration.');
        }

        return ['draft' => $d, 'cost_usd' => round($cost, 5), 'condensed' => $condensed, 'calls' => $calls];
    }

    /**
     * Per-segment transcript text: the words whose start falls inside the segment.
     * Words before the first or after the last segment attach to that segment.
     *
     * @param  list<array{position:int,ts_start:float,ts_end:float,summary:?string}>  $segments
     * @param  list<array{w:string,start:float,end:float}>  $words
     * @return list<array{position:int,ts_start:float,ts_end:float,text:string}>
     */
    public static function segmentTexts(array $segments, array $words): array
    {
        $out = [];
        $n = count($segments);
        foreach ($segments as $i => $s) {
            $lo = $i === 0 ? -INF : $s['ts_start'];
            $hi = $i === $n - 1 ? INF : $segments[$i + 1]['ts_start'];
            $text = implode(' ', array_column(array_filter($words, fn ($w) => $w['start'] >= $lo && $w['start'] < $hi), 'w'));
            $out[] = ['position' => $s['position'], 'ts_start' => $s['ts_start'], 'ts_end' => $s['ts_end'], 'text' => $text];
        }

        return $out;
    }

    /**
     * @param  list<array{position:int,ts_start:float,ts_end:float,summary:?string}>  $segments
     * @param  list<array{w:string,start:float,end:float}>  $words
     * @return array{0: string, 1: float, 2: int}
     */
    private function digest(array $segments, array $words): array
    {
        $texts = self::segmentTexts($segments, $words);
        $limit = (int) config('flowzapp.pipeline.digest_batch_chars', 8000);
        $batches = [];
        $cur = [];
        $size = 0;
        foreach ($texts as $t) {
            if ($cur !== [] && $size + mb_strlen($t['text']) > $limit) {
                $batches[] = $cur;
                $cur = [];
                $size = 0;
            }
            $cur[] = $t;
            $size += mb_strlen($t['text']);
        }
        if ($cur !== []) {
            $batches[] = $cur;
        }

        $digests = [];
        $cost = 0.0;
        foreach ($batches as $batch) {
            $res = $this->llm->complete(Prompts::DIGEST_SYSTEM, Prompts::digestUser($batch), 4096);
            $cost += (float) $res['cost_usd'];
            $d = Json::fromText($res['text']);
            foreach (is_array($d['segments'] ?? null) ? $d['segments'] : [] as $row) {
                if (is_array($row) && isset($row['position'], $row['digest']) && trim((string) $row['digest']) !== '') {
                    $digests[(int) $row['position']] = trim((string) $row['digest']);
                }
            }
        }

        $lines = array_map(fn ($t) => sprintf('[%.1f–%.1f] (segment %d) %s', $t['ts_start'], $t['ts_end'], $t['position'], $digests[$t['position']] ?? $t['text']), $texts);

        return [implode("\n", $lines), $cost, count($batches)];
    }
}
