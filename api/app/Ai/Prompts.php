<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Generation prompts (F9). Kept in one place because prompt changes are code
 * changes: re-run spike/generation-eval before merging (08 §4). The markers
 * ("[segment]", "[generate]") let FakeLlm pick a scripted reply.
 */
final class Prompts
{
    public const SEGMENT_SYSTEM = <<<'TXT'
[segment] You split a narrated screen-recording transcript into the distinct actions the person performed.
Rules: use only what is in the transcript; one segment per distinct action; merge filler, repetition and corrections into the final action;
segments are contiguous, ordered and non-overlapping, covering the narrated part of the recording; each has a short summary (imperative, one line)
and a confidence 0–1 for how clearly the transcript supports it. Output JSON only: {"segments":[{"ts_start":0.0,"ts_end":12.4,"summary":"…","confidence":0.9}]}.
If fewer than two real actions can be identified, return {"halt":true,"reason":"…"}.
TXT;

    public const GENERATE_SYSTEM = <<<'TXT'
[generate] You turn a narrated screen-recording transcript, already split into action segments, into a draft standard operating procedure (SOP).
Rules: use ONLY what is in the transcript — never invent steps, tools, names or values; one step per segment, in order, keeping the segment's time range;
write instructions in the imperative, one sentence where possible; add a warning only when the narration states a risk; add expected_result only when the
narration states what should happen; set confidence low and explain in note when the narration is unclear. Output JSON only, exactly this shape:
{"title":"…","purpose":"…","scope":"…","prerequisites":["…"],"steps":[{"segment":1,"instruction":"…","warning":null,"expected_result":null,"note":null,"confidence":0.9}],"outcome":"…"}
TXT;

    public const REWRITE_SYSTEM = <<<'TXT'
[rewrite] You rewrite standard-operating-procedure text into clear procedural language (FR-801).
Rules: keep every fact, value, name and order exactly; imperative mood; one action per sentence; remove hedging and filler; never add steps, warnings or
assumptions that are not in the input; keep the same language as the input. You receive a JSON object of {"key": "text"} pairs. Output JSON only with
the same keys and the rewritten text as values: {"key": "…"}. Leave a value unchanged when it is already clear.
TXT;

    public const TRANSLATE_SYSTEM = <<<'TXT'
[translate] You translate standard-operating-procedure text (FR-803). Translate faithfully: keep numbers, product names, UI labels in quotes, URLs and
code exactly; keep the imperative register; do not add or drop anything. You receive a target language code and a JSON object of {"key": "text"} pairs.
Output JSON only with the same keys and translated values: {"key": "…"}.
TXT;

    public const TITLE_SYSTEM = <<<'TXT'
[title] You suggest titles for a standard operating procedure from its purpose and steps. Titles are specific, 3–8 words, noun phrases naming the
process ("Issue a refund for orders under 30 days"), never generic ("Process"). Output JSON only: {"titles":["…","…","…"]}.
TXT;

    /**
     * @param  list<array{w:string,start:float,end:float}>  $words
     */
    public static function transcriptLines(array $words, float $window = 10.0): string
    {
        if ($words === []) {
            return '';
        }
        $lines = [];
        $buf = [];
        $start = $words[0]['start'];
        foreach ($words as $w) {
            if ($buf && $window <= $w['start'] - $start) {
                $lines[] = sprintf('[%.1f–%.1f] %s', $start, end($buf)['end'], implode(' ', array_column($buf, 'w')));
                $buf = [];
                $start = $w['start'];
            }
            $buf[] = $w;
        }
        $lines[] = sprintf('[%.1f–%.1f] %s', $start, end($buf)['end'], implode(' ', array_column($buf, 'w')));

        return implode("\n", $lines);
    }

    public static function segmentUser(string $title, float $duration, string $lines): string
    {
        return "Recording: {$title}\nDuration: ".round($duration)." seconds\nTranscript (each line is \"[start–end] words\"):\n\n{$lines}";
    }

    /**
     * @param  list<array{position:int,ts_start:float,ts_end:float,summary:?string}>  $segments
     */
    public static function generateUser(string $title, array $segments, string $lines): string
    {
        $seg = implode("\n", array_map(fn ($s) => sprintf('%d. [%.1f–%.1f] %s', $s['position'], $s['ts_start'], $s['ts_end'], $s['summary'] ?? ''), $segments));

        return "Recording: {$title}\n\nSegments:\n{$seg}\n\nTranscript:\n{$lines}";
    }
}
