<?php

declare(strict_types=1);

namespace Tests\Feature\Recordings;

use App\Ai\FakeLlm;
use App\Jobs\Pipeline\TranscribeRecording;
use App\Models\Document;
use App\Models\MediaAsset;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Models\Space;
use App\Pipeline\FakeTranscriber;
use App\Pipeline\Vision;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** Epic D end to end with fake providers: recording → transcript → segments → draft with steps bound to time ranges. */
final class PipelineTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeTranscriber::$text = null;
        FakeLlm::$responses = [
            '[segment]' => json_encode(['segments' => [
                ['ts_start' => 0, 'ts_end' => 20, 'summary' => 'Open the client folder', 'confidence' => 0.9],
                ['ts_start' => 20, 'ts_end' => 40, 'summary' => 'Duplicate the intake template', 'confidence' => 0.85],
                ['ts_start' => 40, 'ts_end' => 60, 'summary' => 'Send the welcome email', 'confidence' => 0.4],
            ]]),
            '[generate]' => json_encode([
                'title' => 'Client onboarding', 'purpose' => 'Set up a new client.', 'scope' => 'Every new client.', 'prerequisites' => ['Shared drive access'],
                'steps' => [
                    ['segment' => 1, 'instruction' => 'Open the client folder in the shared drive.', 'warning' => null, 'expected_result' => null, 'note' => null, 'confidence' => 0.9],
                    ['segment' => 2, 'instruction' => 'Duplicate the intake template and rename it with the client name.', 'warning' => 'Do not edit the template itself.', 'expected_result' => 'A new file named after the client.', 'note' => null, 'confidence' => 0.85],
                    ['segment' => 3, 'instruction' => 'Send the welcome email from the shared inbox.', 'warning' => null, 'expected_result' => null, 'note' => 'The inbox name was unclear.', 'confidence' => 0.4],
                ],
                'outcome' => 'The client has a folder, an intake document and a welcome email.',
            ]),
        ];
    }

    private function recording($ws, $admin): Recording
    {
        return app(CurrentWorkspace::class)->runAs($ws->id, fn () => Recording::create([
            'space_id' => Space::query()->firstOrFail()->id, 'uploaded_by' => $admin->id, 'title' => 'Onboarding walkthrough',
            'storage_key' => "{$ws->id}/r/source.webm", 'state' => 'uploaded', 'duration_sec' => 60,
        ]));
    }

    public function test_pipeline_produces_a_verifiable_draft_bound_to_the_recording(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $rec = $this->recording($ws, $admin);

        TranscribeRecording::dispatch($ws->id, $rec->id, 'h1');   // sync queue runs the whole chain

        $h = $this->wsHeaders($ws);
        $r = $this->actingAs($admin)->getJson("/api/v1/recordings/{$rec->id}", $h)->assertOk();
        $this->assertSame('draft_ready', $r->json('data.state'), (string) $r->json('data.failure_reason'));
        $docId = $r->json('data.document_id');
        $this->assertNotNull($docId);

        $doc = $this->actingAs($admin)->getJson("/api/v1/documents/{$docId}", $h)->assertOk()->json('data');
        $this->assertSame('draft', $doc['state']);
        $this->assertSame('Client onboarding', $doc['title']);
        $this->assertSame('Set up a new client.', $doc['content']['purpose']);
        $this->assertCount(3, $doc['steps']);
        $this->assertSame([1, 2, 3], array_column($doc['steps'], 'position'));
        $this->assertSame(20.0, (float) $doc['steps'][1]['source_ts_start']);
        $this->assertSame(40.0, (float) $doc['steps'][1]['source_ts_end']);
        $this->assertTrue($doc['steps'][1]['is_critical']);                   // warning → critical
        $this->assertStringContainsString('Warning: Do not edit', $doc['steps'][1]['note']);
        $this->assertStringContainsString('Low confidence', $doc['steps'][2]['note']);
        $this->assertNull($doc['steps'][0]['verified_at'], 'generated steps start unverified (FR-315)');

        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($rec, $docId): void {
            $this->assertSame(['transcribe', 'segment', 'frames', 'generate'], PipelineJob::query()->where('recording_id', $rec->id)->orderBy('id')->pluck('stage')->all());
            $this->assertSame(4, PipelineJob::query()->where('recording_id', $rec->id)->where('status', 'succeeded')->count());
            $this->assertSame($rec->id, Document::query()->findOrFail($docId)->source_recording_id);
            $this->assertSame(3, $rec->segments()->count());
        });

        // Re-running is idempotent for the same input hash and creates a second draft for a new one; the first draft is untouched.
        TranscribeRecording::dispatch($ws->id, $rec->id, 'h1');
        TranscribeRecording::dispatch($ws->id, $rec->id, 'h2');
        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($rec, $docId): void {
            $this->assertSame(2, Document::query()->where('source_recording_id', $rec->id)->count());
            $this->assertSame('Client onboarding', Document::query()->findOrFail($docId)->title);
        });
    }

    public function test_screen_changes_shape_segments_and_repeated_screens_share_one_frame(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $rec = $this->recording($ws, $admin);
        $vision = new class extends Vision
        {
            public int $scans = 0;

            public function sceneChanges(string $inputUrl, ?float $threshold = null, ?int $fps = null): array
            {
                $this->scans++;

                return [21.0, 41.5];
            }

            public function frameAt(string $inputUrl, float $ts): ?string
            {
                return 'jpeg@'.$ts;
            }

            public function dhash(string $imageBytes): ?string
            {
                return str_starts_with($imageBytes, 'jpeg@42.5') ? '0f0f0f0f0f0f0f0f' : 'ffff0000ffff0000';   // steps 1 and 2 show the same screen
            }
        };
        $this->app->instance(Vision::class, $vision);

        TranscribeRecording::dispatch($ws->id, $rec->id, 'h1');

        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($rec, $vision): void {
            $rec->refresh();
            $this->assertSame([21.0, 41.5], array_map('floatval', $rec->scene_changes ?? []));
            $segs = $rec->segments()->orderBy('position')->get();
            $this->assertSame(21.0, (float) $segs[1]->ts_start, 'boundary at 20 snapped to the screen change at 21');
            $this->assertSame(41.5, (float) $segs[2]->ts_start, 'boundary at 40 snapped to 41.5');
            $this->assertSame($segs[0]->frame_asset_id, $segs[1]->frame_asset_id, 'same screen: one stored frame');
            $this->assertNotSame($segs[0]->frame_asset_id, $segs[2]->frame_asset_id);
            $this->assertSame(2, MediaAsset::query()->where('recording_id', $rec->id)->where('kind', 'frame')->count());

            $doc = $rec->document()->firstOrFail();
            $this->assertSame($segs[0]->frame_asset_id, $doc->steps()->where('position', 1)->value('media_asset_id'));
            $this->assertSame(1, $vision->scans);
        });

        TranscribeRecording::dispatch($ws->id, $rec->id, 'h2');   // a re-run reuses the stored scene list
        $this->assertSame(1, $vision->scans);
    }

    public function test_generation_halts_cleanly_when_the_recording_shows_no_process(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $rec = $this->recording($ws, $admin);
        FakeLlm::$responses['[segment]'] = json_encode(['halt' => true, 'reason' => 'only background music']);

        TranscribeRecording::dispatch($ws->id, $rec->id, 'h1');

        $r = $this->actingAs($admin)->getJson("/api/v1/recordings/{$rec->id}", $this->wsHeaders($ws))->assertOk();
        $this->assertSame('failed', $r->json('data.state'));
        $this->assertSame('segment', $r->json('data.failed_stage'));
        $this->assertStringContainsString('background music', $r->json('data.failure_reason'));
        $this->assertNull($r->json('data.document_id'));
    }

    public function test_no_speech_halts_at_transcription(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $rec = $this->recording($ws, $admin);
        FakeTranscriber::$text = 'um';

        TranscribeRecording::dispatch($ws->id, $rec->id, 'h1');

        $r = $this->actingAs($admin)->getJson("/api/v1/recordings/{$rec->id}", $this->wsHeaders($ws));
        $this->assertSame('failed', $r->json('data.state'));
        $this->assertStringContainsString('No speech', $r->json('data.failure_reason'));
    }
}
