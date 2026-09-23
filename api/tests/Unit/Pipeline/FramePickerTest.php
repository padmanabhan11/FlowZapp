<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Pipeline\FramePicker;
use App\Pipeline\Vision;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** D3-T2: frames are taken once the screen settles, and near-identical frames are stored once. */
final class FramePickerTest extends TestCase
{
    public function test_frame_is_taken_after_the_last_screen_change_in_the_segment(): void
    {
        $this->assertSame(15.0, FramePicker::timestampFor(10, 20, []), 'no screen change: midpoint');
        $this->assertSame(17.0, FramePicker::timestampFor(10, 20, [12.0, 16.0, 25.0]), 'one second after the change at 16');
        $this->assertSame(19.5, FramePicker::timestampFor(10, 20, [19.0]), 'change near the end: halfway to the end');
        $this->assertSame(15.0, FramePicker::timestampFor(10, 20, [19.8]), 'a change in the last 0.3 s belongs to the next step');
    }

    public function test_hamming_distance_and_hash_from_greyscale(): void
    {
        $this->assertSame(0, Vision::hamming('ffff0000ffff0000', 'ffff0000ffff0000'));
        $this->assertSame(1, Vision::hamming('ffff0000ffff0000', 'ffff0000ffff0001'));
        $this->assertSame(64, Vision::hamming('0000000000000000', 'ffffffffffffffff'));

        $falling = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $falling[] = 255 - $x * 20;
            }
        }
        $this->assertSame('ffffffffffffffff', Vision::dhashFromGray($falling));
    }

    public function test_near_duplicates_reuse_the_earlier_frame_and_different_screens_do_not(): void
    {
        $vision = new class extends Vision
        {
            /** @var array<string,string> frame bytes by timestamp */
            public array $hashes = ['frame-5' => 'ffff0000ffff0000', 'frame-15' => 'ffff0000ffff0003', 'frame-25' => '0f0f0f0f0f0f0f0f'];

            public function frameAt(string $inputUrl, float $ts): ?string
            {
                return 'frame-'.(int) $ts;
            }

            public function dhash(string $imageBytes): ?string
            {
                return $this->hashes[$imageBytes] ?? null;
            }
        };
        $segments = [
            ['position' => 1, 'ts_start' => 0.0, 'ts_end' => 10.0],
            ['position' => 2, 'ts_start' => 10.0, 'ts_end' => 20.0],
            ['position' => 3, 'ts_start' => 20.0, 'ts_end' => 30.0],
        ];

        $out = (new FramePicker($vision))->pick('video', $segments, []);

        $this->assertNull($out[0]['same_as']);
        $this->assertSame('frame-5', $out[0]['bytes']);
        $this->assertSame(1, $out[1]['same_as'], 'two bits apart: same screen');
        $this->assertSame(2, $out[1]['distance']);
        $this->assertNull($out[1]['bytes'], 'duplicates are not stored again');
        $this->assertNull($out[2]['same_as'], 'a different screen is kept');
    }

    public function test_ffmpeg_detects_scene_changes_and_hashes_real_frames(): void
    {
        if (trim((string) shell_exec('command -v ffmpeg')) === '') {
            $this->markTestSkipped('ffmpeg not installed');
        }
        $video = tempnam(sys_get_temp_dir(), 'fz-scenes-').'.mp4';
        $p = new Process(['ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'color=c=white:s=320x180:d=3',
            '-f', 'lavfi', '-i', 'color=c=white:s=320x180:d=3',
            '-f', 'lavfi', '-i', 'testsrc=s=320x180:d=3',
            '-filter_complex', '[0][1][2]concat=n=3:v=1:a=0', '-r', '10', '-pix_fmt', 'yuv420p', $video]);
        $p->run();
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        try {
            $vision = new Vision;
            $scenes = $vision->sceneChanges($video);
            $this->assertCount(1, $scenes, 'white → white is not a change; white → test pattern is');
            $this->assertEqualsWithDelta(6.0, $scenes[0], 0.6);

            $a = (string) $vision->frameAt($video, 1.0);
            $b = (string) $vision->frameAt($video, 4.0);
            $c = (string) $vision->frameAt($video, 7.5);
            $ha = (string) $vision->dhash($a);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $ha);
            $this->assertLessThanOrEqual(6, Vision::hamming($ha, (string) $vision->dhash($b)));
            $this->assertGreaterThan(6, Vision::hamming($ha, (string) $vision->dhash($c)));
        } finally {
            @unlink($video);
        }
    }
}
