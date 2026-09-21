<?php

declare(strict_types=1);

namespace App\Pipeline;

use Symfony\Component\Process\Process;

/** FFmpeg helpers used by the worker image (03 §5: FFmpeg must be present in the worker). */
final class Audio
{
    /** Mono 16 kHz 48 kbps MP3 — keeps a 60-minute recording under Whisper's 25 MB cap. Returns a temp path. */
    public static function extractMp3(string $inputUrl): string
    {
        $out = tempnam(sys_get_temp_dir(), 'fz-audio-').'.mp3';
        $p = new Process(['ffmpeg', '-y', '-v', 'error', '-i', $inputUrl, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '48k', $out]);
        $p->setTimeout(1800)->run();
        if (! $p->isSuccessful()) {
            throw new PipelineFailed('The recording could not be read. It may be corrupted — upload a different file.');
        }

        return $out;
    }

    /** One JPEG frame at $ts seconds. Returns the bytes. */
    public static function frameAt(string $inputUrl, float $ts): string
    {
        $out = tempnam(sys_get_temp_dir(), 'fz-frame-').'.jpg';
        $p = new Process(['ffmpeg', '-y', '-v', 'error', '-ss', sprintf('%.3f', max($ts, 0)), '-i', $inputUrl, '-frames:v', '1', '-q:v', '3', '-vf', 'scale=1280:-2', $out]);
        $p->setTimeout(120)->run();
        try {
            if (! $p->isSuccessful() || ! is_file($out)) {
                throw new \RuntimeException('frame extraction failed at '.$ts);
            }

            return (string) file_get_contents($out);
        } finally {
            @unlink($out);
        }
    }

    public static function duration(string $inputUrl): ?float
    {
        $p = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $inputUrl]);
        $p->setTimeout(120)->run();

        return $p->isSuccessful() ? (float) trim($p->getOutput()) : null;
    }
}
