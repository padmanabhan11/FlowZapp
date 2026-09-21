<?php

declare(strict_types=1);

namespace App\Pipeline;

use Illuminate\Support\Facades\Http;

/**
 * OpenAI Whisper API with word-level timestamps. Audio is streamed from the
 * signed media URL through a temp file (Whisper needs a multipart upload and
 * caps files at 25 MB, so the worker extracts a mono MP3 first with FFmpeg).
 */
final class WhisperTranscriber implements Transcriber
{
    public const PRICE_PER_MIN = 0.006;

    public function __construct(private readonly string $apiKey) {}

    public function transcribe(string $signedMediaUrl, float $durationSec): array
    {
        $audio = Audio::extractMp3($signedMediaUrl);
        try {
            $res = Http::withToken($this->apiKey)->timeout(900)
                ->attach('file', fopen($audio, 'r'), 'audio.mp3')
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1', 'response_format' => 'verbose_json',
                    'timestamp_granularities[]' => 'word',
                ])->throw()->json();
        } finally {
            @unlink($audio);
        }

        $words = array_map(fn ($w) => ['w' => (string) $w['word'], 'start' => (float) $w['start'], 'end' => (float) $w['end'], 'conf' => null], $res['words'] ?? []);
        $conf = null;
        if (! empty($res['segments'])) {
            $conf = array_sum(array_map(fn ($s) => exp((float) ($s['avg_logprob'] ?? -1.0)), $res['segments'])) / count($res['segments']);
        }

        return [
            'provider' => 'whisper', 'language' => $res['language'] ?? null, 'confidence' => $conf,
            'full_text' => (string) ($res['text'] ?? ''), 'words' => $words,
            'cost_usd' => round($durationSec / 60 * self::PRICE_PER_MIN, 5),
        ];
    }
}
