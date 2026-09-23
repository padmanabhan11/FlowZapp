<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Models\Space;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/** M4-T3 metrics endpoint and M4-T4 health alerts. */
final class OpsMetricsTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    public function test_metrics_endpoint_needs_the_token_and_reports_pipeline_cost_index_and_chat(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme', 'team');
        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($admin): void {
            $sid = Space::query()->firstOrFail()->id;
            $rec = Recording::create(['space_id' => $sid, 'uploaded_by' => $admin->id, 'title' => 'r', 'storage_key' => 'k/s.webm', 'state' => 'draft_ready', 'duration_sec' => 60]);
            foreach ([['generate', 'succeeded', 0.20, 4000], ['generate', 'succeeded', 0.10, 2000], ['generate', 'failed', 0.0, 500], ['transcribe', 'succeeded', 0.05, 30000]] as $i => [$stage, $status, $cost, $ms]) {
                PipelineJob::create(['recording_id' => $rec->id, 'stage' => $stage, 'job_key' => "{$rec->id}:{$stage}:{$i}", 'status' => $status, 'cost_usd' => $cost,
                    'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(10)->addMilliseconds($ms), 'error' => $status === 'failed' ? 'boom' : null]);
            }
            $s = ChatSession::create(['user_id' => $admin->id]);
            ChatMessage::create(['session_id' => $s->id, 'role' => 'assistant', 'content' => 'a', 'refused' => false, 'latency_ms' => 900]);
            ChatMessage::create(['session_id' => $s->id, 'role' => 'assistant', 'content' => 'n', 'refused' => true, 'latency_ms' => 300]);
        });

        $this->get('/api/v1/internal/metrics')->assertStatus(404);
        config(['services.metrics.token' => 'tok']);
        $this->get('/api/v1/internal/metrics')->assertStatus(404);
        $body = $this->withToken('tok')->get('/api/v1/internal/metrics')->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->getContent();
        $this->assertStringContainsString('flowzapp_pipeline_jobs{stage="generate",result="succeeded"} 2', $body);
        $this->assertStringContainsString('flowzapp_pipeline_success_rate{stage="generate"} 0.6667', $body);
        $this->assertStringContainsString('flowzapp_pipeline_duration_ms{stage="generate",quantile="0.95"} 4000', $body);
        $this->assertStringContainsString('flowzapp_generation_cost_per_sop_usd 0.175', $body);
        $this->assertStringContainsString('flowzapp_index_unindexed_documents 0', $body);
        $this->assertStringContainsString('flowzapp_chat_refusal_rate 0.5', $body);
        $this->assertStringContainsString('flowzapp_workspaces 1', $body);
    }

    public function test_health_check_alerts_on_generation_failures_once_an_hour(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme', 'team');
        app(CurrentWorkspace::class)->runAs($ws->id, function () use ($admin): void {
            $sid = Space::query()->firstOrFail()->id;
            $rec = Recording::create(['space_id' => $sid, 'uploaded_by' => $admin->id, 'title' => 'r', 'storage_key' => 'k/s.webm', 'state' => 'failed', 'duration_sec' => 60]);
            foreach ([['failed', 'Claude API 500'], ['failed', 'Claude API 500'], ['succeeded', null], ['failed', 'Too little narration. Record it again.']] as $i => [$status, $err]) {
                PipelineJob::create(['recording_id' => $rec->id, 'stage' => 'generate', 'job_key' => "{$rec->id}:generate:{$i}", 'status' => $status, 'error' => $err]);
            }
        });
        Log::shouldReceive('channel')->with('alerts')->once()->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn ($msg, $ctx) => $msg === 'pipeline.generation_failures' && $ctx['failed'] === 2 && $ctx['succeeded'] === 1);
        Log::shouldReceive('info', 'warning', 'debug')->zeroOrMoreTimes();

        $this->artisan('ops:check-health')->expectsOutputToContain('alerts: pipeline.generation_failures — generation 1 ok / 2 failed / 1 halted')->assertSuccessful();
        $this->artisan('ops:check-health')->expectsOutputToContain('healthy')->assertSuccessful();   // throttled: not raised again this hour
    }
}
