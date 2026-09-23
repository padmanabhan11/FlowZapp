<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\FakeLlm;
use App\Ai\LlmDriver;
use App\Jobs\Pipeline\TranscribeRecording;
use App\Pipeline\Audio;
use App\Pipeline\DraftWriter;
use App\Pipeline\FramePicker;
use App\Pipeline\PipelineFailed;
use App\Pipeline\Segmenter;
use App\Pipeline\Vision;
use App\Providers\MediaServiceProvider;
use Illuminate\Console\Command;

/**
 * Generation and segmentation eval harness (D4-T5, D2-T4, D0-T2).
 *
 * Runs the product pipeline code — the same Transcriber drivers, Segmenter,
 * FramePicker and DraftWriter the queue jobs use — over a folder of local
 * recordings, without a database or object storage. Output matches the
 * spike's layout, so spike/generation-eval/score.py scores it:
 *
 *   <out>/<recording>/<provider>/transcript.json, scenes.json, segments.json,
 *       draft.json, draft.md, frames/step-NN.jpg, metrics.json
 *   <out>/runs.csv               one row per (recording, provider)
 *   <out>/human-review.csv       blank row per run for the reviewer (generation)
 *   <out>/segments-review.csv    model boundaries per run; reviewer adds the true ones
 *
 * Runs are resumable: finished (recording, provider) pairs are skipped unless --force.
 */
final class PipelineEval extends Command
{
    protected $signature = 'pipeline:eval
        {recordings : folder of .webm/.mp4/.mov recordings}
        {--out=storage/app/eval : output folder}
        {--providers= : comma-separated transcription drivers (whisper,deepgram); default: the configured driver}
        {--limit=0 : only the first N recordings}
        {--force : redo runs that already exist}
        {--mock : no provider calls; exercises the pipeline with scripted transcript and model output}';

    protected $description = 'Run the generation pipeline over local recordings and write eval output for spike/generation-eval/score.py';

    private const VIDEO_EXT = ['webm', 'mp4', 'mov', 'mkv', 'm4v'];

    private const RUN_COLS = ['recording', 'provider', 'model', 'duration_sec', 'transcribe_sec', 'mean_conf', 'language', 'generate_sec', 'total_sec',
        'halted', 'halt_reason', 'steps', 'low_conf_steps', 'frames', 'input_tokens', 'output_tokens', 'transcribe_cost_usd', 'generate_cost_usd',
        'total_cost_usd', 'validation', 'segments', 'scene_changes', 'segmentation_source', 'frames_deduplicated', 'condensed'];

    private const REVIEW_COLS = ['recording', 'provider', 'human_step_count', 'steps_with_correct_frame', 'verdict', 'edited_draft_path', 'notes'];

    private const SEGMENT_COLS = ['recording', 'provider', 'model_boundaries', 'human_boundaries', 'notes'];

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('recordings'), '/');
        $out = rtrim((string) $this->option('out'), '/');
        if (! is_dir($dir)) {
            $this->error("{$dir} is not a folder");

            return self::FAILURE;
        }
        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            if (trim((string) shell_exec('command -v '.$tool)) === '') {
                $this->error("{$tool} not found on PATH");

                return self::FAILURE;
            }
        }
        $mock = (bool) $this->option('mock');
        $providers = $mock ? ['fake'] : array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('providers') ?: config('flowzapp.transcription_driver'))))));
        foreach ($providers as $p) {
            if (! in_array($p, ['whisper', 'deepgram', 'fake'], true)) {
                $this->error("unknown transcription driver {$p} (whisper, deepgram)");

                return self::FAILURE;
            }
        }
        if ($mock) {
            app()->instance(LlmDriver::class, new FakeLlm);
        }

        $files = array_values(array_filter(glob($dir.'/*') ?: [], fn ($f) => is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::VIDEO_EXT, true)));
        sort($files);
        if ((int) $this->option('limit') > 0) {
            $files = array_slice($files, 0, (int) $this->option('limit'));
        }
        if ($files === []) {
            $this->warn("no recordings in {$dir}");

            return self::SUCCESS;
        }
        @mkdir($out, 0775, true);

        foreach ($files as $file) {
            foreach ($providers as $provider) {
                $this->runOne($file, $provider, $out, $mock);
            }
        }
        $this->info("done — score with: python spike/generation-eval/score.py --out {$out}");

        return self::SUCCESS;
    }

    private function runOne(string $file, string $provider, string $out, bool $mock): void
    {
        $rec = pathinfo($file, PATHINFO_FILENAME);
        $rdir = "{$out}/{$rec}/{$provider}";
        if (is_file("{$rdir}/metrics.json") && ! $this->option('force')) {
            $this->line("  {$rec} / {$provider}: done, skipping (--force to redo)");

            return;
        }
        @mkdir("{$rdir}/frames", 0775, true);
        $duration = Audio::duration($file) ?? 0.0;
        $this->line(sprintf('  %s / %s: %.1f min', $rec, $provider, $duration / 60));

        $m = ['recording' => $rec, 'provider' => $provider, 'model' => $mock ? 'fake' : (string) config('services.anthropic.model', 'claude'),
            'duration_sec' => round($duration, 1), 'halted' => 'False', 'halt_reason' => '', 'steps' => 0, 'low_conf_steps' => 0, 'frames' => 0,
            'input_tokens' => '', 'output_tokens' => '', 'validation' => ''];
        $t0 = microtime(true);
        $segments = [];
        try {
            // 1 — transcribe (same halt rules as TranscribeRecording, FR-314)
            $tr = MediaServiceProvider::transcriber($provider)->transcribe($file, $duration);
            $m['transcribe_sec'] = round(microtime(true) - $t0, 1);
            $m['mean_conf'] = $tr['confidence'] === null ? '' : round($tr['confidence'], 3);
            $m['language'] = $tr['language'];
            $m['transcribe_cost_usd'] = round($tr['cost_usd'], 4);
            file_put_contents("{$rdir}/transcript.json", json_encode($tr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if (trim($tr['full_text']) === '' || count($tr['words']) < 10) {
                throw new PipelineFailed('no speech detected');
            }
            if ($tr['confidence'] !== null && $tr['confidence'] < TranscribeRecording::MIN_CONFIDENCE) {
                throw new PipelineFailed(sprintf('transcript confidence %.2f below %.2f', $tr['confidence'], TranscribeRecording::MIN_CONFIDENCE));
            }

            // 2 — screen changes and segmentation
            $tg = microtime(true);
            $scenes = app(Vision::class)->sceneChanges($file);
            file_put_contents("{$rdir}/scenes.json", json_encode($scenes));
            $m['scene_changes'] = count($scenes);
            if ($mock) {
                $this->scriptMock($duration, $tr['words']);
            }
            $seg = app(Segmenter::class)->segment($rec, $duration, $tr['words'], $scenes);
            foreach ($seg['segments'] as $i => $s) {
                $segments[] = ['position' => $i + 1] + $s;
            }
            file_put_contents("{$rdir}/segments.json", json_encode($segments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $m['segments'] = count($segments);
            $m['segmentation_source'] = $seg['source'];

            // 3 — frames
            $frames = app(FramePicker::class)->pick($file, $segments, $scenes, $duration);
            $framePath = [];
            $dedup = 0;
            foreach ($frames as $f) {
                if ($f['bytes'] !== null) {
                    $framePath[$f['position']] = sprintf('frames/segment-%02d.jpg', $f['position']);
                    file_put_contents("{$rdir}/".$framePath[$f['position']], $f['bytes']);
                } elseif ($f['same_as'] !== null && isset($framePath[$f['same_as']])) {
                    $framePath[$f['position']] = $framePath[$f['same_as']];
                    $dedup++;
                }
            }
            $m['frames_deduplicated'] = $dedup;

            // 4 — draft
            $w = app(DraftWriter::class)->write($rec, array_map(fn ($s) => ['position' => $s['position'], 'ts_start' => $s['ts_start'], 'ts_end' => $s['ts_end'], 'summary' => $s['summary']], $segments), $tr['words']);
            $m['generate_sec'] = round(microtime(true) - $tg, 1);
            $m['generate_cost_usd'] = round($seg['cost_usd'] + $w['cost_usd'], 4);
            $m['condensed'] = $w['condensed'] ? 'True' : 'False';
            $draft = $w['draft'];
            $steps = $this->steps($draft, $segments);
            $m['steps'] = count($steps);
            $m['low_conf_steps'] = count(array_filter($steps, fn ($s) => ($s['confidence'] ?? 1) < 0.6));
            $problems = [];
            foreach ($steps as $i => $s) {
                if ($s['ts_start'] === null) {
                    $problems[] = 'step '.($i + 1).' has no segment';
                }
                if (isset($framePath[$s['segment'] ?? -1])) {
                    $dest = sprintf('%s/frames/step-%02d.jpg', $rdir, $i + 1);
                    copy("{$rdir}/".$framePath[$s['segment']], $dest);
                    $m['frames']++;
                }
            }
            $m['validation'] = implode('; ', $problems);
            file_put_contents("{$rdir}/draft.json", json_encode($draft + ['steps_resolved' => $steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            file_put_contents("{$rdir}/draft.md", $this->markdown($draft, $steps, $rec, $provider));
        } catch (PipelineFailed $e) {
            $m['halted'] = 'True';
            $m['halt_reason'] = $e->getMessage();
            file_put_contents("{$rdir}/draft.md", "# HALTED\n\n{$e->getMessage()}\n");
        } catch (\Throwable $e) {
            $m['halted'] = 'True';
            $m['halt_reason'] = 'error: '.$e->getMessage();
            file_put_contents("{$rdir}/draft.md", "# ERROR\n\n{$e->getMessage()}\n");
        }
        $m['total_sec'] = round(microtime(true) - $t0, 1);
        $m['total_cost_usd'] = round((float) ($m['transcribe_cost_usd'] ?? 0) + (float) ($m['generate_cost_usd'] ?? 0), 4);

        file_put_contents("{$rdir}/metrics.json", json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->append("{$out}/runs.csv", self::RUN_COLS, $m);
        $this->append("{$out}/human-review.csv", self::REVIEW_COLS, ['recording' => $rec, 'provider' => $provider]);
        $bounds = array_map(fn ($s) => sprintf('%.1f', $s['ts_start']), array_slice($segments, 1));
        $this->append("{$out}/segments-review.csv", self::SEGMENT_COLS, ['recording' => $rec, 'provider' => $provider, 'model_boundaries' => implode(' ', $bounds)]);
        $this->line(sprintf('    %s', $m['halted'] === 'True' ? 'halted: '.$m['halt_reason'] : "{$m['segments']} segments, {$m['steps']} steps, {$m['frames']} frames, {$m['total_sec']}s, \${$m['total_cost_usd']}"));
    }

    /**
     * Resolves each generated step to its segment's time range, as GenerateDraft does.
     *
     * @param  array<string,mixed>  $draft
     * @param  list<array<string,mixed>>  $segments
     * @return list<array<string,mixed>>
     */
    private function steps(array $draft, array $segments): array
    {
        $out = [];
        foreach (is_array($draft['steps'] ?? null) ? $draft['steps'] : [] as $s) {
            if (! is_array($s) || trim((string) ($s['instruction'] ?? '')) === '') {
                continue;
            }
            $seg = null;
            foreach ($segments as $candidate) {
                if (isset($s['segment']) && $candidate['position'] === (int) $s['segment']) {
                    $seg = $candidate;
                }
            }
            $out[] = $s + ['ts_start' => $seg['ts_start'] ?? null, 'ts_end' => $seg['ts_end'] ?? null];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $d
     * @param  list<array<string,mixed>>  $steps
     */
    private function markdown(array $d, array $steps, string $rec, string $provider): string
    {
        $l = ['# '.($d['title'] ?? $rec), '', "_Draft generated from `{$rec}` via {$provider} by the product pipeline. Not approved._", '',
            '## Purpose', (string) ($d['purpose'] ?? ''), '', '## Scope', (string) ($d['scope'] ?? ''), '', '## Prerequisites'];
        $pre = is_array($d['prerequisites'] ?? null) ? $d['prerequisites'] : [];
        $l = array_merge($l, $pre === [] ? ['- (none)'] : array_map(fn ($p) => '- '.$p, $pre), ['', '## Steps']);
        foreach ($steps as $i => $s) {
            $n = $i + 1;
            $l[] = "{$n}. {$s['instruction']}  ";
            $l[] = $s['ts_start'] === null ? '   ⏱ (no segment)' : sprintf('   ⏱ %.1f–%.1fs · confidence %s', $s['ts_start'], $s['ts_end'], $s['confidence'] ?? '?');
            foreach (['warning' => '⚠ ', 'expected_result' => 'Expected: ', 'note' => 'Note: '] as $k => $label) {
                if (! empty($s[$k])) {
                    $l[] = "   > {$label}{$s[$k]}";
                }
            }
            $l[] = sprintf('   ![step %d](frames/step-%02d.jpg)', $n, $n);
        }
        $l = array_merge($l, ['', '## Outcome', (string) ($d['outcome'] ?? '')]);

        return implode("\n", $l)."\n";
    }

    /**
     * @param  list<string>  $cols
     * @param  array<string,mixed>  $row
     */
    private function append(string $path, array $cols, array $row): void
    {
        $new = ! is_file($path);
        $fh = fopen($path, 'a');
        if ($fh === false) {
            return;
        }
        if ($new) {
            fputcsv($fh, $cols, ',', '"', '');
        }
        fputcsv($fh, array_map(fn ($c) => is_bool($row[$c] ?? null) ? ($row[$c] ? 'True' : 'False') : (string) ($row[$c] ?? ''), $cols), ',', '"', '');
        fclose($fh);
    }

    /**
     * --mock: scripted model replies that split the spoken span into five
     * equal segments, so the whole harness can be checked without API keys.
     *
     * @param  list<array{w:string,start:float,end:float}>  $words
     */
    private function scriptMock(float $duration, array $words): void
    {
        $first = $words[0]['start'] ?? 0.0;
        $last = $words[count($words) - 1]['end'] ?? $duration;
        $span = ($last - $first) / 5;
        $segs = [];
        $steps = [];
        for ($i = 0; $i < 5; $i++) {
            $segs[] = ['ts_start' => round($first + $i * $span, 2), 'ts_end' => round($first + ($i + 1) * $span, 2), 'summary' => 'Mock action '.($i + 1), 'confidence' => 0.8];
            $steps[] = ['segment' => $i + 1, 'instruction' => 'Mock step '.($i + 1).'.', 'warning' => null, 'expected_result' => null, 'note' => null, 'confidence' => 0.8];
        }
        FakeLlm::$responses = [
            '[segment]' => (string) json_encode(['segments' => $segs]),
            '[digest]' => (string) json_encode(['segments' => []]),
            '[generate]' => (string) json_encode(['title' => 'Mock procedure', 'purpose' => 'Mock purpose.', 'scope' => 'Mock scope.', 'prerequisites' => [], 'steps' => $steps, 'outcome' => 'Mock outcome.']),
        ];
    }
}
