<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Ai\Json;
use App\Ai\LlmDriver;
use App\Ai\Prompts;

/**
 * Stage 2 logic (03 §5), shared by the SegmentRecording job and the
 * pipeline:eval harness so the evaluation measures the product code.
 *
 * Two signals: what the person says (transcript cues and pauses, read by the
 * model) and what the screen does (scene changes from Vision, D2-T2). The
 * model sees the screen changes as hints; afterwards each boundary that falls
 * close to a screen change is moved onto it, because the click that changes
 * the screen is where one action ends and the next begins.
 */
final class Segmenter
{
    public function __construct(private readonly LlmDriver $llm) {}

    /**
     * @param  list<array{w:string,start:float,end:float}>  $words
     * @param  list<float>  $scenes
     * @return array{segments: list<array{ts_start: float, ts_end: float, summary: ?string, confidence: ?float}>, cost_usd: float, source: string}
     *
     * @throws PipelineFailed when the recording shows no process with distinct steps
     */
    public function segment(string $title, float $duration, array $words, array $scenes = []): array
    {
        $lines = Prompts::transcriptLines($words);
        $res = $this->llm->complete(Prompts::SEGMENT_SYSTEM, Prompts::segmentUser($title, $duration, $lines, $scenes), 4096);

        $data = Json::fromText($res['text']);
        if (is_array($data) && ! empty($data['halt'])) {
            throw new PipelineFailed('This recording does not seem to show a process with distinct steps: '.($data['reason'] ?? 'too little narrated action').'. Record it again, narrating each action as you do it.');
        }
        $raw = is_array($data['segments'] ?? null) ? $data['segments'] : [];
        $segments = [];
        foreach ($raw as $s) {
            if (! is_array($s) || ! isset($s['ts_start'], $s['ts_end']) || (float) $s['ts_end'] <= (float) $s['ts_start']) {
                continue;
            }
            $segments[] = [
                'ts_start' => (float) $s['ts_start'], 'ts_end' => (float) $s['ts_end'],
                'summary' => isset($s['summary']) ? mb_substr((string) $s['summary'], 0, 500) : null,
                'confidence' => isset($s['confidence']) ? max(0.0, min(1.0, (float) $s['confidence'])) : null,
            ];
        }
        $source = 'model';
        if (count($segments) < 2) {
            $segments = self::paragraphFallback($words, $scenes);   // 03 §5 failure behaviour
            $source = 'fallback';
        }
        if (count($segments) < 2) {
            throw new PipelineFailed('Too little narration to identify steps. Record it again, explaining each action as you do it.');
        }

        $tolerance = (float) config('flowzapp.pipeline.snap_tolerance_sec', 2.0);

        return ['segments' => self::snapToScenes($segments, $scenes, $tolerance), 'cost_usd' => (float) $res['cost_usd'], 'source' => $source];
    }

    /**
     * Moves each inner boundary onto the nearest screen change within
     * $tolerance seconds, keeping segments ordered and at least one second long.
     * The first start and last end are left alone.
     *
     * @template T of array{ts_start: float, ts_end: float}
     *
     * @param  list<T>  $segments
     * @param  list<float>  $scenes
     * @return list<T>
     */
    public static function snapToScenes(array $segments, array $scenes, float $tolerance): array
    {
        if ($scenes === [] || count($segments) < 2) {
            return $segments;
        }
        usort($segments, fn ($a, $b) => $a['ts_start'] <=> $b['ts_start']);
        for ($i = 1, $n = count($segments); $i < $n; $i++) {
            $boundary = $segments[$i]['ts_start'];
            $best = null;
            foreach ($scenes as $t) {
                if (abs($t - $boundary) <= $tolerance && ($best === null || abs($t - $boundary) < abs($best - $boundary))) {
                    $best = $t;
                }
            }
            if ($best === null) {
                continue;
            }
            $prevStart = $segments[$i - 1]['ts_start'];
            $nextEnd = $segments[$i]['ts_end'];
            if ($best - $prevStart < 1.0 || $nextEnd - $best < 1.0) {
                continue; // would squeeze a neighbour to nothing
            }
            // Shift the previous end by the same amount so a pause between the two stays a pause.
            $prevEnd = $segments[$i - 1]['ts_end'] + ($best - $boundary);
            $segments[$i]['ts_start'] = round($best, 3);
            $segments[$i - 1]['ts_end'] = round(min($best, max($prevStart + 1.0, $prevEnd)), 3);
        }

        return $segments;
    }

    /**
     * Fallback when the model output is unusable: split the spoken span at
     * screen changes when there are any, otherwise into ~20-second windows.
     *
     * @param  list<array{w:string,start:float,end:float}>  $words
     * @param  list<float>  $scenes
     * @return list<array{ts_start: float, ts_end: float, summary: ?string, confidence: ?float}>
     */
    public static function paragraphFallback(array $words, array $scenes = []): array
    {
        if (count($words) < 10) {
            return [];
        }
        $first = $words[0]['start'];
        $last = $words[count($words) - 1]['end'];
        $cuts = array_values(array_filter($scenes, fn ($t) => $t > $first + 3.0 && $t < $last - 3.0));
        if ($cuts !== []) {
            $bounds = array_merge([$first], $cuts, [$last]);
            $out = [];
            for ($i = 0; $i < count($bounds) - 1; $i++) {
                $spoken = array_filter($words, fn ($w) => $w['start'] >= $bounds[$i] && $w['start'] < $bounds[$i + 1]);
                if ($spoken === []) {
                    continue; // silent stretch: fold into the neighbour
                }
                if ($out !== [] && $bounds[$i + 1] - $bounds[$i] < 3.0) {
                    $out[count($out) - 1]['ts_end'] = $bounds[$i + 1];

                    continue;
                }
                $out[] = self::guess($bounds[$i], $bounds[$i + 1]);
            }
            if (count($out) >= 2) {
                return $out;
            }
        }

        $out = [];
        $start = $first;
        $end = $first;
        $open = false;
        foreach ($words as $w) {
            if ($open && $w['start'] - $start >= 20) {
                $out[] = self::guess($start, $end);
                $start = $w['start'];
            }
            $end = $w['end'];
            $open = true;
        }
        $out[] = self::guess($start, $end);

        return $out;
    }

    /**
     * A fallback segment: no summary, low confidence so the draft flags it.
     *
     * @return array{ts_start: float, ts_end: float, summary: ?string, confidence: ?float}
     */
    private static function guess(float $start, float $end): array
    {
        return ['ts_start' => $start, 'ts_end' => $end, 'summary' => null, 'confidence' => 0.3];
    }
}
