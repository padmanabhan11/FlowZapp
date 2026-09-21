<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Media\MediaStorage;
use App\Models\MediaAsset;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Pipeline\Audio;
use Illuminate\Support\Facades\Log;

/**
 * Stage 3 — one representative frame per segment (midpoint), deduplicated by
 * content hash. Failure behaviour per 03 §5: continue without screenshots.
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

    protected function next(): ?string
    {
        return GenerateDraft::class;
    }

    protected function run(Recording $rec, PipelineJob $job): void
    {
        $storage = app(MediaStorage::class);
        $url = $storage->signedUrl($rec->storage_key, 900);
        $seen = [];
        foreach ($rec->segments()->get() as $seg) {
            if ($seg->frame_asset_id !== null) {
                continue; // idempotent re-run
            }
            $mid = ((float) $seg->ts_start + (float) $seg->ts_end) / 2;
            try {
                $bytes = Audio::frameAt($url, $mid);
            } catch (\Throwable $e) {
                Log::warning('frame extraction skipped', ['recording' => $rec->id, 'segment' => $seg->position, 'error' => $e->getMessage()]);
                continue;
            }
            $hash = hash('sha256', $bytes);
            if (isset($seen[$hash])) {
                $seg->forceFill(['frame_asset_id' => $seen[$hash]])->save(); // near-identical frame: reuse
                continue;
            }
            $key = sprintf('%s/%s/frames/%03d.jpg', $rec->workspace_id, $rec->id, $seg->position);
            $storage->put($key, $bytes, 'image/jpeg');
            $asset = MediaAsset::create(['recording_id' => $rec->id, 'kind' => 'frame', 'storage_key' => $key, 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes)]);
            $seen[$hash] = $asset->id;
            $seg->forceFill(['frame_asset_id' => $asset->id])->save();
        }
    }
}
