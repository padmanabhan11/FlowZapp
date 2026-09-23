<?php

declare(strict_types=1);

namespace App\Pipeline;

use Symfony\Component\Process\Process;

/**
 * Video analysis for the pipeline, all through FFmpeg so the worker image
 * needs nothing beyond what it already has (api/Dockerfile.worker).
 *
 * Bound in the container so tests can substitute a scripted instance; every
 * method fails soft (empty list / null) because 03 §5 says a missing frame
 * or scene list must never stop a draft.
 */
class Vision
{
    /**
     * D2-T2: timestamps (seconds) where the screen changes substantially.
     * The video is sampled at a low frame rate and downscaled first; screen
     * recordings change in discrete jumps, so this keeps a 60-minute file
     * cheap to scan while still catching page and dialog changes.
     *
     * @return list<float>
     */
    public function sceneChanges(string $inputUrl, ?float $threshold = null, ?int $fps = null): array
    {
        $threshold ??= (float) config('flowzapp.pipeline.scene_threshold', 0.3);
        $fps ??= (int) config('flowzapp.pipeline.scene_fps', 2);
        $filter = sprintf("fps=%d,scale=320:-2,select='gt(scene,%.3f)',showinfo", max(1, $fps), $threshold);
        $p = new Process(['ffmpeg', '-hide_banner', '-nostats', '-i', $inputUrl, '-an', '-vf', $filter, '-f', 'null', '-']);
        $p->setTimeout(1800)->run();
        if (! $p->isSuccessful()) {
            return [];
        }

        return self::parseShowinfo($p->getErrorOutput());
    }

    /** One JPEG frame at $ts seconds, or null when FFmpeg cannot produce one. */
    public function frameAt(string $inputUrl, float $ts): ?string
    {
        try {
            return Audio::frameAt($inputUrl, $ts);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * D3-T2: 64-bit difference hash of an image, as 16 hex characters.
     * The image is reduced to 9×8 greyscale by FFmpeg; each bit says whether a
     * pixel is brighter than its right-hand neighbour. Two screenshots of the
     * same screen (a moved cursor, a blinking caret, re-encoding noise) differ
     * by a few bits; different screens differ by dozens.
     */
    public function dhash(string $imageBytes): ?string
    {
        $p = new Process(['ffmpeg', '-hide_banner', '-v', 'error', '-i', 'pipe:0', '-vf', 'scale=9:8:flags=area,format=gray', '-frames:v', '1', '-f', 'rawvideo', 'pipe:1']);
        $p->setInput($imageBytes);
        $p->setTimeout(60)->run();
        $raw = $p->getOutput();
        if (! $p->isSuccessful() || strlen($raw) < 72) {
            return null;
        }

        return self::dhashFromGray(array_values(unpack('C72', $raw) ?: []));
    }

    /**
     * @param  list<int>  $gray  72 greyscale values, row-major 9×8
     */
    public static function dhashFromGray(array $gray): string
    {
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= $gray[$y * 9 + $x] > $gray[$y * 9 + $x + 1] ? '1' : '0';
            }
        }
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex((int) bindec($nibble));
        }

        return $hex;
    }

    /** Number of differing bits between two hex hashes of equal length. */
    public static function hamming(string $a, string $b): int
    {
        $n = 0;
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            $x = hexdec($a[$i]) ^ hexdec($b[$i]);
            $n += substr_count(decbin((int) $x), '1');
        }

        return $n + 4 * abs(strlen($a) - strlen($b));
    }

    /**
     * @return list<float>
     */
    public static function parseShowinfo(string $stderr): array
    {
        preg_match_all('/pts_time:\s*([0-9]+(?:\.[0-9]+)?)/', $stderr, $m);
        $out = [];
        foreach ($m[1] as $t) {
            $v = round((float) $t, 2);
            if ($out === [] || $v - end($out) >= 0.5) {    // one change per half second is plenty
                $out[] = $v;
            }
        }

        return $out;
    }
}
