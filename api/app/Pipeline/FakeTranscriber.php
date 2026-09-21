<?php

declare(strict_types=1);

namespace App\Pipeline;

/** Deterministic transcript for tests and local development (TRANSCRIPTION_DRIVER=fake). */
final class FakeTranscriber implements Transcriber
{
    public static ?string $text = null;

    public function transcribe(string $signedMediaUrl, float $durationSec): array
    {
        $text = self::$text ?? 'First open the client folder in the shared drive. Then duplicate the intake template and rename it with the client name. '
            .'Next set the delivery date in the tracker and finally send the welcome email from the shared inbox.';
        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        $step = max($durationSec, 60) / max(count($tokens), 1);
        $words = [];
        foreach ($tokens as $i => $w) {
            $words[] = ['w' => $w, 'start' => round($i * $step, 3), 'end' => round(($i + 1) * $step, 3), 'conf' => 0.95];
        }

        return ['provider' => 'fake', 'language' => 'en', 'confidence' => 0.95, 'full_text' => $text, 'words' => $words, 'cost_usd' => 0.0];
    }
}
