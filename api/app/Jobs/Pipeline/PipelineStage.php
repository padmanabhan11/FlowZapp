<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\PipelineJob;
use App\Models\Recording;
use App\Pipeline\PipelineFailed;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Base for the five pipeline stages (03 §5): one job class per stage, idempotent
 * via pipeline_jobs.job_key, independently retryable, run as the recording's
 * workspace. Subclasses implement run() and name their stage and queue.
 */
abstract class PipelineStage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 3600;

    public function __construct(public readonly string $workspaceId, public readonly string $recordingId, public readonly string $inputHash = '')
    {
        $this->onQueue($this->queueName());
    }

    abstract protected function stage(): string;

    abstract protected function recordingState(): string;

    /** @return string the next stage's job class, or null when this is the last stage */
    abstract protected function next(): ?string;

    abstract protected function run(Recording $rec, PipelineJob $job): void;

    protected function queueName(): string
    {
        return 'pipeline';
    }

    public function uniqueId(): string
    {
        return $this->jobKey();
    }

    public function jobKey(): string
    {
        return "{$this->recordingId}:{$this->stage()}:{$this->inputHash}";
    }

    public function handle(CurrentWorkspace $current): void
    {
        $current->runAs($this->workspaceId, function (): void {
            $rec = Recording::query()->find($this->recordingId);
            if ($rec === null) {
                return; // deleted while queued
            }
            $job = PipelineJob::query()->firstOrCreate(
                ['job_key' => $this->jobKey()],
                ['recording_id' => $rec->id, 'stage' => $this->stage(), 'status' => 'queued'],
            );
            if ($job->status === 'succeeded') {
                $this->advance($rec); // idempotent re-run: skip straight to the next stage

                return;
            }
            $job->forceFill(['status' => 'running', 'attempts' => $job->attempts + 1, 'started_at' => now(), 'error' => null])->save();
            $rec->forceFill(['state' => $this->recordingState(), 'failed_stage' => null, 'failure_reason' => null])->save();

            try {
                $this->run($rec, $job);
            } catch (Throwable $e) {
                $job->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
                $final = $e instanceof PipelineFailed || $this->attempts() >= $this->tries;
                if ($final) {
                    $rec->forceFill(['state' => 'failed', 'failed_stage' => $this->stage(), 'failure_reason' => mb_substr($this->reasonFor($e), 0, 500)])->save();
                    $this->fail($e);

                    return;
                }
                throw $e; // retried with backoff
            }

            $job->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();
            $this->advance($rec);
        });
    }

    protected function advance(Recording $rec): void
    {
        $nextClass = $this->next();
        if ($nextClass !== null) {
            $nextClass::dispatch($this->workspaceId, $rec->id, $this->inputHash);
        }
    }

    /** Plain-language failure copy (S10): the cause and the available action. */
    protected function reasonFor(Throwable $e): string
    {
        return $e instanceof PipelineFailed ? $e->getMessage() : 'Processing failed at the '.$this->stage().' stage. Retry, or upload a different file.';
    }
}
