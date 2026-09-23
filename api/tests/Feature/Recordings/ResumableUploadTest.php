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

/** C3-T1 / C3-T3: an upload interrupted mid-way resumes from the parts storage already holds. */
final class ResumableUploadTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    public function test_an_interrupted_upload_resumes_with_only_the_missing_parts_and_completes(): void
    {
        Queue::fake();
        [$ws, $admin] = $this->makeWorkspace('acme', 'team');
        $h = $this->wsHeaders($ws);
        $sid = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);

        // 40 MB → three 16 MB parts.
        $t = $this->actingAs($admin)->postJson('/api/v1/recordings/upload-url', [
            'filename' => 'onboarding.webm', 'mime_type' => 'video/webm', 'size_bytes' => 40 * 1024 * 1024, 'duration_sec' => 600, 'space_id' => $sid,
        ], $h)->assertStatus(201)->json('data');
        $this->assertCount(3, $t['parts']);

        // The network drops after part 1 lands; the tab is reloaded.
        /** @var FakeMediaStorage $storage */
        $storage = app(MediaStorage::class);
        $storage->parts[$t['upload_id']] = [1 => ['etag' => 'e1', 'size' => 16 * 1024 * 1024]];

        $r = $this->actingAs($admin)->getJson("/api/v1/recordings/{$t['recording_id']}/upload", $h)->assertOk()->json('data');
        $this->assertSame([['part_number' => 1, 'etag' => 'e1', 'size' => 16 * 1024 * 1024]], $r['parts_done']);
        $this->assertSame([2, 3], array_column($r['parts'], 'part_number'), 'only the missing parts get fresh URLs');
        $this->assertStringContainsString('resumed=1', $r['parts'][0]['url']);
        $this->assertSame('video/webm', $r['mime_type']);

        // Client sends parts 2 and 3, then completes with all three.
        $this->actingAs($admin)->postJson('/api/v1/recordings', [
            'recording_id' => $t['recording_id'], 'parts' => [['part_number' => 1, 'etag' => 'e1'], ['part_number' => 2, 'etag' => 'e2'], ['part_number' => 3, 'etag' => 'e3']],
        ], $h)->assertStatus(201)->assertJsonPath('data.state', 'uploaded');
        Queue::assertPushedOn('pipeline', TranscribeRecording::class);

        // Once registered, it is no longer resumable.
        $this->actingAs($admin)->getJson("/api/v1/recordings/{$t['recording_id']}/upload", $h)->assertStatus(409);
    }

    public function test_only_the_uploader_or_an_admin_can_resume_and_discarding_aborts_the_upload(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme', 'team');
        $h = $this->wsHeaders($ws);
        $sid = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);
        $editor = $this->addMember($ws, 'ed@example.test', 'editor');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => SpaceMember::create(['space_id' => $sid, 'user_id' => $editor->id, 'role' => 'editor']));

        $t = $this->actingAs($admin)->postJson('/api/v1/recordings/upload-url', ['filename' => 'x.webm', 'mime_type' => 'video/webm', 'size_bytes' => 10, 'space_id' => $sid], $h)->json('data');
        $this->actingAs($editor)->getJson("/api/v1/recordings/{$t['recording_id']}/upload", $h)->assertStatus(403);

        /** @var FakeMediaStorage $storage */
        $storage = app(MediaStorage::class);
        $this->assertArrayHasKey($t['upload_id'], $storage->uploads);
        $this->actingAs($admin)->deleteJson("/api/v1/recordings/{$t['recording_id']}", [], $h)->assertOk();
        $this->assertArrayNotHasKey($t['upload_id'], $storage->uploads, 'discarding a pending upload aborts it at the provider');
    }

    public function test_stale_pending_uploads_are_abandoned_after_a_week(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme', 'team');
        $h = $this->wsHeaders($ws);
        $sid = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);
        $old = $this->actingAs($admin)->postJson('/api/v1/recordings/upload-url', ['filename' => 'a.webm', 'mime_type' => 'video/webm', 'size_bytes' => 10, 'space_id' => $sid], $h)->json('data.recording_id');
        $new = $this->actingAs($admin)->postJson('/api/v1/recordings/upload-url', ['filename' => 'b.webm', 'mime_type' => 'video/webm', 'size_bytes' => 10, 'space_id' => $sid], $h)->json('data.recording_id');
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => Recording::query()->whereKey($old)->update(['created_at' => now()->subDays(8)]));

        $this->artisan('recordings:abandon-stale')->assertSuccessful();
        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($old, $new): void {
            $this->assertNull(Recording::query()->find($old));
            $this->assertNotNull(Recording::query()->find($new));
        });
    }
}
