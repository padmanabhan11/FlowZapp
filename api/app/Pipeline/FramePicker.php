<?php

declare(strict_types=1);

namespace App\Pipeline;

/**
 * Stage 3 logic (03 §5), shared by the ExtractFrames job and the
 * pipeline:eval harness.
 *
 * Which moment: if the screen changed during the segment, the frame is taken
 * shortly after the last change, once the new screen has settled — that is
 * the screen the step leaves the person on. Otherwise the midpoint.
 *
 * Which frames are the same (D3-T2): frames whose difference hashes are
 * within flowzapp.pipeline.dedup_max_distance bits of an earlier kept frame
 * reuse that frame instead of storing a near-duplicate.
 */
final class FramePicker
{
    public function __construct(private readonly Vision $vision) {}

    /**
     * @param  list<float>  $scenes
     */
    public static function timestampFor(float $start, float $end, array $scenes, float $settle = 1.0): float
    {
        $inside = array_values(array_filter($scenes, fn ($t) => $t >= $start && $t < $end - 0.3));
        if ($inside === []) {
            return round(($start + $end) / 2, 3);
        }
        $last = end($inside);

        return round(min($last + $settle, $last + ($end - $last) / 2), 3);
    }

    /**
     * @param  list<array{position:int,ts_start:float,ts_end:float}>  $segments
     * @param  list<float>  $scenes
     * @return list<array{position:int, ts:float, bytes:?string, hash:?string, same_as:?int, distance:?int}> same_as is the position of the earlier kept frame this one duplicates
     */
    public function pick(string $inputUrl, array $segments, array $scenes, ?float $duration = null): array
    {
        $maxDistance = (int) config('flowzapp.pipeline.dedup_max_distance', 6);
        $kept = [];   // position => hash
        $out = [];
        foreach ($segments as $seg) {
            $ts = self::timestampFor($seg['ts_start'], $seg['ts_end'], $scenes);
            if ($duration !== null && $duration > 0) {
                $ts = min($ts, max($duration - 0.5, 0.0));
            }
            $bytes = $this->vision->frameAt($inputUrl, $ts);
            if ($bytes === null) {
                $out[] = ['position' => $seg['position'], 'ts' => $ts, 'bytes' => null, 'hash' => null, 'same_as' => null, 'distance' => null];

                continue;
            }
            $hash = $this->vision->dhash($bytes) ?? 'sha:'.hash('sha256', $bytes);
            [$sameAs, $distance] = self::match($hash, $kept, $maxDistance);
            if ($sameAs === null) {
                $kept[$seg['position']] = $hash;
            }
            $out[] = ['position' => $seg['position'], 'ts' => $ts, 'bytes' => $sameAs === null ? $bytes : null, 'hash' => $hash, 'same_as' => $sameAs, 'distance' => $distance];
        }

        return $out;
    }

    /**
     * Closest kept frame within $maxDistance bits. Hashes that are not
     * difference hashes (FFmpeg could not scale the image) only match exactly.
     *
     * @param  array<int,string>  $kept
     * @return array{0: ?int, 1: ?int}
     */
    public static function match(string $hash, array $kept, int $maxDistance): array
    {
        $best = null;
        $bestD = null;
        foreach ($kept as $pos => $h) {
            if (str_starts_with($hash, 'sha:') || str_starts_with($h, 'sha:')) {
                $d = $hash === $h ? 0 : PHP_INT_MAX;
            } else {
                $d = Vision::hamming($hash, $h);
            }
            if ($d <= $maxDistance && ($bestD === null || $d < $bestD)) {
                $best = $pos;
                $bestD = $d;
            }
        }

        return [$best, $bestD];
    }
}
