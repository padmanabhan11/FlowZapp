<?php

declare(strict_types=1);

namespace App\Pipeline;

use Illuminate\Support\Facades\Http;

/**
 * Deepgram nova-3 via URL ingestion: Deepgram fetches the signed media URL itself, so nothing is proxied.
 * A local file path (the pipeline:eval harness) is sent as compressed audio instead.
 */
final class DeepgramTranscriber implements Transcriber
{
    public const PRICE_PER_MIN = 0.0077;

    public function __construct(private readonly string $apiKey) {}

    public function transcribe(string $signedMediaUrl, float $durationSec): array
    {
        $endpoint = 'https://api.deepgram.com/v1/listen?model=nova-3&smart_format=true&punctuate=true&detect_language=true';
        $http = Http::withHeaders(['Authorization' => "Token {$this->apiKey}"])->timeout(900);
        if (is_file($signedMediaUrl)) {
            $audio = Audio::extractMp3($signedMediaUrl);
            try {
                $res = $http->withBody((string) file_get_contents($audio), 'audio/mpeg')->post($endpoint)->throw()->json();
            } finally {
                @unlink($audio);
            }
        } else {
            $res = $http->post($endpoint, ['url' => $signedMediaUrl])->throw()->json();
        }

        $alt = $res['results']['channels'][0]['alternatives'][0] ?? [];
        $words = array_map(fn ($w) => [
            'w' => (string) ($w['punctuated_word'] ?? $w['word']), 'start' => (float) $w['start'], 'end' => (float) $w['end'], 'conf' => (float) ($w['confidence'] ?? 0),
        ], $alt['words'] ?? []);
        $conf = $words ? array_sum(array_column($words, 'conf')) / count($words) : 0.0;

        return [
            'provider' => 'deepgram', 'language' => $res['results']['channels'][0]['detected_language'] ?? null, 'confidence' => $conf,
            'full_text' => (string) ($alt['transcript'] ?? ''), 'words' => $words,
            'cost_usd' => round($durationSec / 60 * self::PRICE_PER_MIN, 5),
        ];
    }
}
