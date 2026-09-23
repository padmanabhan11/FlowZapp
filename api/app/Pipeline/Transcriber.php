<?php

declare(strict_types=1);

namespace App\Pipeline;

/**
 * Speech-to-text driver (03 §0.2, §1). Word-level timestamps are
 * non-negotiable — D5 depends on them. Implementations arrive with Epic D
 * after the D0 evaluation; NullTranscriber makes the gap explicit.
 */
interface Transcriber
{
    /**
     * @return array{provider: string, language: ?string, confidence: ?float, full_text: string, words: list<array{w: string, start: float, end: float, conf: ?float}>, cost_usd: float}
     */
    public function transcribe(string $signedMediaUrl, float $durationSec): array;
}
