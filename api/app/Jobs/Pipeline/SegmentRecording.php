<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Media\MediaStorage;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Models\RecordingSegment;
use App\Pipeline\Segmenter;
use App\Pipeline\Vision;
use Illuminate\Support\Facades\Log;

/**
 * Stage 2 — Segment into distinct actions (03 §5). Reads both the narration
 * and the screen: scene changes are detected once per recording (D2-T2) and
 * kept on the recording for the frames stage. Falls back to splitting at
 * screen changes, then to ~20-second paragraphs, when the model output is unusable.
 */
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

    protected function next(): string
    {
        return ExtractFrames::class;
    }

    protected function run(Recording $rec, PipelineJob $job): void
    {
        $t = $rec->transcript()->firstOrFail();
        $scenes = $this->scenes($rec);
        $res = app(Segmenter::class)->segment((string) $rec->title, (float) ($rec->duration_sec ?? 0), $t->words ?? [], $scenes);
        $job->forceFill(['cost_usd' => $res['cost_usd']])->save();

        RecordingSegment::query()->where('recording_id', $rec->id)->delete();
        foreach ($res['segments'] as $i => $s) {
            RecordingSegment::create([
                'recording_id' => $rec->id, 'position' => $i + 1,
                'ts_start' => round($s['ts_start'], 3), 'ts_end' => round($s['ts_end'], 3),
                'summary' => $s['summary'], 'confidence' => $s['confidence'],
            ]);
        }
    }

    /**
     * Screen changes for this recording, detected on first use. A failed scan
     * is stored as an empty list: segmentation then relies on the narration alone.
     *
     * @return list<float>
     */
    private function scenes(Recording $rec): array
    {
        if (is_array($rec->scene_changes)) {
            return array_map('floatval', $rec->scene_changes);
        }
        try {
            $url = app(MediaStorage::class)->signedUrl($rec->storage_key, 3600);
            $scenes = app(Vision::class)->sceneChanges($url);
        } catch (\Throwable $e) {
            Log::warning('scene detection skipped', ['recording' => $rec->id, 'error' => $e->getMessage()]);
            $scenes = [];
        }
        $rec->forceFill(['scene_changes' => $scenes])->save();

        return $scenes;
    }
}
