<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Media\MediaStorage;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Models\Transcript;
use App\Pipeline\PipelineFailed;
use App\Pipeline\Transcriber;

/** Stage 1 — Transcribe (03 §5). Halts cleanly when the audio is unusable (FR-314). */
final class TranscribeRecording extends PipelineStage
{
    public const MIN_CONFIDENCE = 0.55;

    protected function stage(): string
    {
        return 'transcribe';
    }

    protected function recordingState(): string
    {
        return 'transcribing';
    }

    protected function next(): string
    {
        return SegmentRecording::class;
    }

    protected function run(Recording $rec, PipelineJob $job): void
    {
        $url = app(MediaStorage::class)->signedUrl($rec->storage_key, 900);
        $result = app(Transcriber::class)->transcribe($url, (float) ($rec->duration_sec ?? 0));

        if (trim($result['full_text']) === '' || count($result['words']) < 10) {
            throw new PipelineFailed('No speech was detected in this recording. Retry with audio, or upload a different file.');
        }
        if ($result['confidence'] !== null && $result['confidence'] < self::MIN_CONFIDENCE) {
            throw new PipelineFailed('The narration was too unclear to transcribe reliably. Record it again with less background noise, or upload a different file.');
        }

        Transcript::query()->updateOrCreate(['recording_id' => $rec->id], [
            'language' => $result['language'], 'confidence' => $result['confidence'], 'full_text' => $result['full_text'],
            'words' => $result['words'], 'provider' => $result['provider'],
        ]);
        $job->forceFill(['cost_usd' => $result['cost_usd']])->save();
    }
}
