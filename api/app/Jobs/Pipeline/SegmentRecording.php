<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\PipelineJob;
use App\Models\Recording;

/** Stage 2 — Segment into distinct actions. Implemented in Epic D (Claude API); placeholder keeps the chain honest. */
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
        return null; // Epic D: ExtractFrames → GenerateDraft
    }

    protected function run(Recording $rec, PipelineJob $job): void
    {
        throw new \App\Pipeline\PipelineFailed('Draft generation is not available yet in this build (Epic D).');
    }
}
