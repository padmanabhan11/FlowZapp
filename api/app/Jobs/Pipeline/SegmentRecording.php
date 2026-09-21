<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Ai\Json;
use App\Ai\LlmDriver;
use App\Ai\Prompts;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Models\RecordingSegment;
use App\Pipeline\PipelineFailed;

/** Stage 2 — Segment into distinct actions (03 §5). Falls back to paragraph segmentation when the model output is unusable. */
final class SegmentRecording extends PipelineStage
{
    protected function stage(): string
    {
        return 'segment';
    }

    protected function recordingState(): string
    {
        return 'segmenting';
    }

    protected function next(): ?string
    {
        return ExtractFrames::class;
    }

    protected function run(Recording $rec, PipelineJob $job): void
    {
        $t = $rec->transcript()->firstOrFail();
        $words = $t->words ?? [];
        $lines = Prompts::transcriptLines($words);
        $res = app(LlmDriver::class)->complete(Prompts::SEGMENT_SYSTEM, Prompts::segmentUser((string) $rec->title, (float) ($rec->duration_sec ?? 0), $lines), 4096);
        $job->forceFill(['cost_usd' => $res['cost_usd']])->save();

        $data = Json::fromText($res['text']);
        if (is_array($data) && ! empty($data['halt'])) {
            throw new PipelineFailed('This recording does not seem to show a process with distinct steps: '.($data['reason'] ?? 'too little narrated action').'. Record it again, narrating each action as you do it.');
        }
        $segments = is_array($data['segments'] ?? null) ? $data['segments'] : [];
        $segments = array_values(array_filter($segments, fn ($s) => isset($s['ts_start'], $s['ts_end']) && (float) $s['ts_end'] > (float) $s['ts_start']));
        if (count($segments) < 2) {
            $segments = $this->paragraphFallback($words);   // 03 §5 failure behaviour: fall back to paragraph segmentation
        }
        if (count($segments) < 2) {
            throw new PipelineFailed('Too little narration to identify steps. Record it again, explaining each action as you do it.');
        }

        RecordingSegment::query()->where('recording_id', $rec->id)->delete();
        foreach ($segments as $i => $s) {
            RecordingSegment::create([
                'recording_id' => $rec->id, 'position' => $i + 1,
                'ts_start' => round((float) $s['ts_start'], 3), 'ts_end' => round((float) $s['ts_end'], 3),
                'summary' => isset($s['summary']) ? mb_substr((string) $s['summary'], 0, 500) : null,
                'confidence' => isset($s['confidence']) ? max(0, min(1, (float) $s['confidence'])) : null,
            ]);
        }
    }

    /** ~20-second windows over the spoken span. @param list<array{w:string,start:float,end:float}> $words @return list<array<string,mixed>> */
    private function paragraphFallback(array $words): array
    {
        if (count($words) < 10) {
            return [];
        }
        $out = [];
        $start = $words[0]['start'];
        $buf = [];
        foreach ($words as $w) {
            if ($buf && $w['start'] - $start >= 20) {
                $out[] = ['ts_start' => $start, 'ts_end' => end($buf)['end'], 'summary' => null, 'confidence' => 0.3];
                $buf = [];
                $start = $w['start'];
            }
            $buf[] = $w;
        }
        if ($buf) {
            $out[] = ['ts_start' => $start, 'ts_end' => end($buf)['end'], 'summary' => null, 'confidence' => 0.3];
        }

        return $out;
    }
}
