<?php

declare(strict_types=1);

namespace App\Pipeline;



final class NullTranscriber implements Transcriber
{
    public function transcribe(string $signedMediaUrl, float $durationSec): array
    {
        throw new PipelineFailed('No transcription provider is configured yet (TRANSCRIPTION_DRIVER). Run spike/generation-eval to choose one.');
    }
}
