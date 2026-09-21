<?php

declare(strict_types=1);

namespace Tests\Feature\Recordings;

use App\Jobs\Pipeline\TranscribeRecording;
use App\Media\FakeMediaStorage;
use App\Media\MediaStorage;
use App\Models\Recording;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

final class RecordingTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private function spaceId($ws): string
    {
        return app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);
    }

    public function test_upload_flow_presigns_parts_then_registers_and_starts_the_pipeline(): void
    {
        Queue::fake();
        [$ws, $admin] = $this->makeWorkspace('acme');
        $h = $this->wsHeaders($ws);

        $r = $this->actingAs($admin)->postJson('/api/v1/recordings/upload-url', [
            'filename' => 'onboarding.webm', 'mime_type' => 'video/webm', 'size_bytes' => 40 * 1024 * 1024, 'duration_sec' => 600, 'space_id' => $this->spaceId($ws),
        ], $h)->assertStatus(201);
        $this->assertCount(3, $r->json('data.parts'));       // 40 MB / 16 MB parts
        $id = $r->json('data.recording_id');
        $this->assertSame('pending_upload', $this->actingAs($admin)->getJson("/api/v1/recordings/{$id}", $h)->json('data.state'));
        $this->actingAs($admin)->getJson("/api/v1/recordings/{$id}/playback-url", $h)->assertStatus(409);

        /** @var FakeMediaStorage $storage */
        $storage = app(MediaStorage::class);
        $key = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Recording::query()->findOrFail($id)->storage_key);
        $this->assertStringStartsWith($ws->id.'/'.$id.'/', $key);   // {workspace_id}/{recording_id}/…

        $this->actingAs($admin)->postJson('/api/v1/recordings', [
            'recording_id' => $id, 'parts' => [['part_number' => 1, 'etag' => 'a'], ['part_number' => 2, 'etag' => 'b'], ['part_number' => 3, 'etag' => 'c']],
        ], $h)->assertStatus(201)->assertJsonPath('data.state', 'uploaded');
        $this->assertTrue($storage->exists($key));
        Queue::assertPushedOn('pipeline', TranscribeRecording::class);

        // Registering twice is refused; playback URL is signed and short-lived.
        $this->actingAs($admin)->postJson('/api/v1/recordings', ['recording_id' => $id, 'parts' => [['part_number' => 1, 'etag' => 'a']]], $h)->assertStatus(409);
        $url = $this->actingAs($admin)->getJson("/api/v1/recordings/{$id}/playback-url", $h)->assertOk()->json('data.url');
        $this->assertStringContainsString('X-Amz-Expires=900', $url);
    }

    public function test_limits_are_enforced_before_bytes_move(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');   // free: 30 recording minutes / month
        $h = $this->wsHeaders($ws);
        $sid = $this->spaceId($ws);
        $this->actingAs($admin)->postJson('/api/v1/recordings/upload-url', ['filename' => 'x.webm', 'mime_type' => 'video/webm', 'size_bytes' => 3 * 1024 * 1024 * 1024, 'space_id' => $sid], $h)->assertStatus(422);
        $this->actingAs($admin)->postJson('/api/v1/recordings/upload-url', ['filename' => 'x.avi', 'mime_type' => 'video/x-msvideo', 'size_bytes' => 10, 'space_id' => $sid], $h)->assertStatus(422);
        $this->actingAs($admin)->postJson('/api/v1/recordings/upload-url', ['filename' => 'x.webm', 'mime_type' => 'video/webm', 'size_bytes' => 10, 'duration_sec' => 31 * 60, 'space_id' => $sid], $h)
            ->assertStatus(429)->assertJsonPath('error.code', 'plan_limit_exceeded');

        $reader = $this->addMember($ws, 'r@example.test', 'reader');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => SpaceMember::create(['space_id' => $sid, 'user_id' => $reader->id, 'role' => 'reader']));
        $this->actingAs($reader)->postJson('/api/v1/recordings/upload-url', ['filename' => 'x.webm', 'mime_type' => 'video/webm', 'size_bytes' => 10, 'space_id' => $sid], $h)->assertStatus(403);
    }

    public function test_recordings_are_private_to_uploader_and_admins_until_a_draft_exists(): void
    {
        Queue::fake();
        [$ws, $admin] = $this->makeWorkspace('acme');
        $h = $this->wsHeaders($ws);
        $sid = $this->spaceId($ws);
        $editor = $this->addMember($ws, 'e@example.test', 'editor');
        $other = $this->addMember($ws, 'o@example.test', 'editor');
        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($sid, $editor, $other): void {
            SpaceMember::create(['space_id' => $sid, 'user_id' => $editor->id, 'role' => 'editor']);
            SpaceMember::create(['space_id' => $sid, 'user_id' => $other->id, 'role' => 'editor']);
        });

        $id = $this->actingAs($editor)->postJson('/api/v1/recordings/upload-url', ['filename' => 'x.webm', 'mime_type' => 'video/webm', 'size_bytes' => 10, 'space_id' => $sid], $h)->json('data.recording_id');

        $this->actingAs($other)->getJson("/api/v1/recordings/{$id}", $h)->assertStatus(403);
        $this->assertCount(0, $this->actingAs($other)->getJson('/api/v1/recordings', $h)->json('data'));
        $this->actingAs($admin)->getJson("/api/v1/recordings/{$id}", $h)->assertOk();
        $this->assertCount(1, $this->actingAs($admin)->getJson('/api/v1/recordings', $h)->json('data'));
        $this->actingAs($other)->deleteJson("/api/v1/recordings/{$id}", [], $h)->assertStatus(403);

        // Once a draft exists, space viewers may watch the source.
        $docId = $this->actingAs($editor)->postJson('/api/v1/documents', ['space_id' => $sid, 'title' => 'From recording'], $h)->json('data.id');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => Recording::query()->whereKey($id)->update(['document_id' => $docId, 'state' => 'draft_ready']));
        $this->actingAs($other)->getJson("/api/v1/recordings/{$id}", $h)->assertOk()->assertJsonPath('data.document_id', $docId);
    }

    public function test_transcribe_stage_fails_cleanly_when_no_provider_is_configured_and_retry_requeues(): void
    {
        Queue::fake();
        [$ws, $admin] = $this->makeWorkspace('acme');
        $h = $this->wsHeaders($ws);
        $rec = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Recording::create([
            'space_id' => $this->spaceId($ws), 'uploaded_by' => $admin->id, 'storage_key' => "{$ws->id}/x/source.webm", 'state' => 'uploaded', 'duration_sec' => 60,
        ]));

        $this->app->instance(\App\Pipeline\Transcriber::class, new \App\Pipeline\NullTranscriber);
        (new TranscribeRecording($ws->id, $rec->id, 'h1'))->handle(app(CurrentWorkspace::class));

        $r = $this->actingAs($admin)->getJson("/api/v1/recordings/{$rec->id}", $h)->assertOk();
        $this->assertSame('failed', $r->json('data.state'));
        $this->assertSame('transcribe', $r->json('data.failed_stage'));
        $this->assertStringContainsString('transcription provider', $r->json('data.failure_reason'));

        $this->actingAs($admin)->postJson("/api/v1/recordings/{$rec->id}/retry", [], $h)->assertStatus(202)->assertJsonPath('data.state', 'uploaded');
        Queue::assertPushed(TranscribeRecording::class);

        $this->actingAs($admin)->deleteJson("/api/v1/recordings/{$rec->id}", [], $h)->assertOk();
        $this->actingAs($admin)->getJson("/api/v1/recordings/{$rec->id}", $h)->assertStatus(403);
    }
}
