<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\PipelineJob;
use App\Models\Recording;
use App\Models\Space;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** Epic K / S22: counters against plan limits; 429 plan_limit_exceeded with the counter in details; chat is Team-only (402). */
final class UsageTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    public function test_usage_counters_and_document_and_generation_caps_on_free(): void
    {
        Queue::fake();
        [$ws, $admin] = $this->makeWorkspace('acme');   // free: 50 documents, 5 generations
        $h = $this->wsHeaders($ws);
        $sid = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);

        $u = $this->actingAs($admin)->getJson('/api/v1/usage', $h)->assertOk()->json('data');
        $this->assertSame('free', $u['plan']);
        $this->assertSame(50, $u['counters']['documents']['max']);
        $this->assertSame(1, $u['counters']['seats']['used']);
        $this->assertTrue($u['counters']['chat_queries_per_day']['over'], 'free has 0 chat: over from the start');
        $this->assertSame(500, $u['plans']['team']['sop_generations']);

        // Documents: 50 allowed, the 51st is refused with the counter in details.
        for ($i = 1; $i <= 50; $i++) {
            $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'title' => "Doc $i"], $h)->assertStatus(201);
        }
        $r = $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'title' => 'Doc 51'], $h)->assertStatus(429);
        $this->assertSame('plan_limit_exceeded', $r->json('error.code'));
        $this->assertSame(['limit' => 'documents', 'max' => 50, 'used' => 50, 'plan' => 'free'], $r->json('error.details'));
        $this->assertStringContainsString('50 documents', $r->json('error.message'));
        $this->assertTrue($this->actingAs($admin)->getJson('/api/v1/usage', $h)->json('data.counters.documents.over'));

        // Generations: five succeeded generate jobs this month → the next upload is refused before bytes move.
        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($sid, $admin): void {
            $rec = Recording::create(['space_id' => $sid, 'uploaded_by' => $admin->id, 'storage_key' => 'k', 'state' => 'draft_ready']);
            for ($i = 0; $i < 5; $i++) {
                PipelineJob::create(['recording_id' => $rec->id, 'stage' => 'generate', 'job_key' => "seed:generate:$i", 'status' => 'succeeded']);
            }
        });
        $this->assertSame(5, $this->actingAs($admin)->getJson('/api/v1/usage', $h)->json('data.counters.sop_generations.used'));
        $id = $this->actingAs($admin)->postJson('/api/v1/recordings/upload-url', ['filename' => 'x.webm', 'mime_type' => 'video/webm', 'size_bytes' => 10, 'duration_sec' => 60, 'space_id' => $sid], $h)->assertStatus(201)->json('data.recording_id');
        $this->actingAs($admin)->postJson('/api/v1/recordings', ['recording_id' => $id, 'parts' => [['part_number' => 1, 'etag' => 'a']]], $h)
            ->assertStatus(429)->assertJsonPath('error.details.limit', 'sop_generations');

        // Chat is Team-only: 402 with a plain message, not 429.
        $sess = $this->actingAs($admin)->postJson('/api/v1/chat/sessions', [], $h)->assertStatus(201)->json('data.id');
        $this->actingAs($admin)->postJson("/api/v1/chat/sessions/{$sess}/messages", ['content' => 'How?'], $h)->assertStatus(402)->assertJsonPath('error.code', 'plan_required');

        // Portal is 501 until a provider is connected; admin only.
        $this->actingAs($admin)->getJson('/api/v1/billing/portal', $h)->assertStatus(501);
    }
}
