<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Ai\FakeLlm;
use App\Pipeline\PipelineFailed;
use App\Pipeline\Segmenter;
use Tests\TestCase;

/** D2-T2: screen changes place segment boundaries; the fallback splits at them. */
final class SegmentationTest extends TestCase
{
    /**
     * @return list<array{w:string,start:float,end:float}>
     */
    private function words(float $from, float $to, float $every = 1.0): array
    {
        $out = [];
        for ($t = $from; $t < $to; $t += $every) {
            $out[] = ['w' => 'word', 'start' => $t, 'end' => $t + 0.6];
        }

        return $out;
    }

    public function test_boundaries_snap_to_nearby_screen_changes_only(): void
    {
        $segments = [
            ['ts_start' => 0.0, 'ts_end' => 19.0, 'summary' => 'a', 'confidence' => 0.9],
            ['ts_start' => 19.5, 'ts_end' => 40.0, 'summary' => 'b', 'confidence' => 0.9],
            ['ts_start' => 40.0, 'ts_end' => 60.0, 'summary' => 'c', 'confidence' => 0.9],
        ];
        $out = Segmenter::snapToScenes($segments, [18.2, 47.0], 2.0);

        $this->assertSame(18.2, $out[1]['ts_start'], 'boundary 19.5 moves to the screen change at 18.2');
        $this->assertSame(17.7, $out[0]['ts_end'], 'the 0.5 s pause before it is kept');
        $this->assertSame(40.0, $out[2]['ts_start'], 'no screen change within 2 s of 40.0: unchanged');
        $this->assertSame(0.0, $out[0]['ts_start'], 'outer edges never move');
        $this->assertSame(60.0, $out[2]['ts_end']);
    }

    public function test_snapping_never_squeezes_a_segment_below_one_second(): void
    {
        $segments = [
            ['ts_start' => 10.0, 'ts_end' => 11.5],
            ['ts_start' => 11.5, 'ts_end' => 30.0],
        ];
        $this->assertSame(11.5, Segmenter::snapToScenes($segments, [10.4], 2.0)[1]['ts_start']);
    }

    public function test_fallback_splits_at_screen_changes_before_falling_back_to_windows(): void
    {
        $words = $this->words(0, 90);
        $byScene = Segmenter::paragraphFallback($words, [30.0, 61.0]);
        $this->assertSame([[0.0, 30.0], [30.0, 61.0], [61.0, 89.6]], array_map(fn ($s) => [$s['ts_start'], $s['ts_end']], $byScene));

        $byWindow = Segmenter::paragraphFallback($words, []);
        $this->assertCount(5, $byWindow, '~20 s windows over 90 s of speech');
        $this->assertSame([], Segmenter::paragraphFallback($this->words(0, 5), []), 'too little speech');
    }

    public function test_model_sees_screen_changes_and_its_boundaries_are_snapped(): void
    {
        FakeLlm::$responses = ['[segment]' => (string) json_encode(['segments' => [
            ['ts_start' => 0, 'ts_end' => 21, 'summary' => 'Open the report', 'confidence' => 0.9],
            ['ts_start' => 21, 'ts_end' => 45, 'summary' => 'Export it', 'confidence' => 0.8],
        ]])];
        $llm = new class extends FakeLlm
        {
            public string $lastUser = '';

            public function complete(string $system, string $user, int $maxTokens = 4096, float $temperature = 0.0): array
            {
                $this->lastUser = $user;

                return parent::complete($system, $user, $maxTokens, $temperature);
            }
        };

        $res = (new Segmenter($llm))->segment('Monthly report', 45, $this->words(0, 45), [22.4]);

        $this->assertStringContainsString('Screen changes detected at (seconds): 22.4', $llm->lastUser);
        $this->assertSame(22.4, $res['segments'][1]['ts_start']);
        $this->assertSame('model', $res['source']);
    }

    public function test_model_halt_stops_the_pipeline(): void
    {
        FakeLlm::$responses = ['[segment]' => '{"halt":true,"reason":"only music"}'];
        $this->expectException(PipelineFailed::class);
        (new Segmenter(new FakeLlm))->segment('x', 60, $this->words(0, 60));
    }
}
