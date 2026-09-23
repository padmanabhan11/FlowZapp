<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Media\MediaStorage;
use App\Models\MediaAsset;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Pipeline\FramePicker;
use Illuminate\Support\Facades\Log;

/**
 * Stage 3 — one representative frame per segment: after the last screen
 * change in the segment when there is one, otherwise the midpoint. Frames
 * that look the same as an earlier one (perceptual hash, D3-T2) reuse it.
 * Failure behaviour per 03 §5: continue without screenshots.
 * FFmpeg must be in the worker image (api/Dockerfile.worker).
 */
final class ExtractFrames extends PipelineStage
{
    protected function stage(): string
    {
        return 'frames';
    }

    protected function recordingState(): string
    {
        return 'segmenting'; // frames are part of the "segmenting" phase from the user's point of view (S10 states)
    }

    protected function next(): string
    {
        return GenerateDraft::class;
    }

    protected function run(Recording $rec, PipelineJob $job): void
    {
        $segments = $rec->segments()->get();
        $todo = $segments->whereNull('frame_asset_id');
        if ($todo->isEmpty()) {
            return; // idempotent re-run
        }
        $storage = app(MediaStorage::class);
        $url = $storage->signedUrl($rec->storage_key, 3600);
        $input = $segments->map(fn ($s) => ['position' => (int) $s->position, 'ts_start' => (float) $s->ts_start, 'ts_end' => (float) $s->ts_end])->values()->all();
        $scenes = array_map('floatval', $rec->scene_changes ?? []);

        $assetFor = [];   // position => media asset id
        foreach (app(FramePicker::class)->pick($url, $input, $scenes, $rec->duration_sec) as $f) {
            $seg = $segments->firstWhere('position', $f['position']);
            if ($seg === null) {
                continue;
            }
            if ($seg->frame_asset_id !== null) {
                $assetFor[$f['position']] = $seg->frame_asset_id;

                continue;
            }
            if ($f['same_as'] !== null && isset($assetFor[$f['same_as']])) {
                $seg->forceFill(['frame_asset_id' => $assetFor[$f['same_as']]])->save();   // same screen: reuse

                continue;
            }
            if ($f['bytes'] === null) {
                Log::warning('frame extraction skipped', ['recording' => $rec->id, 'segment' => $f['position']]);

                continue;
            }
            $key = sprintf('%s/%s/frames/%03d.jpg', $rec->workspace_id, $rec->id, $f['position']);
            $storage->put($key, $f['bytes'], 'image/jpeg');
            $asset = MediaAsset::create(['recording_id' => $rec->id, 'kind' => 'frame', 'storage_key' => $key, 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($f['bytes'])]);
            $assetFor[$f['position']] = $asset->id;
            $seg->forceFill(['frame_asset_id' => $asset->id])->save();
        }
    }
}
