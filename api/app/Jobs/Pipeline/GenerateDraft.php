<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Ai\Json;
use App\Ai\LlmDriver;
use App\Ai\Prompts;
use App\Audit\Audit;
use App\Documents\Content;
use App\Models\Document;
use App\Models\DocumentStep;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Models\User;
use App\Notifications\RecordingDraftReadyNotification;
use App\Observers\DocumentObserver;
use App\Pipeline\PipelineFailed;
use Illuminate\Support\Facades\DB;

/**
 * Stage 4 — Generate the draft SOP in the F1 structure (F9). Output is always
 * a new draft: never auto-approved, never indexed, never overwriting an earlier
 * draft (re-running creates another document). Each step keeps its source time
 * range and its frame; verified_at stays null until a person checks it (FR-315).
 * Halts with a clear error and no partial draft on unusable output (03 §5).
 */
final class GenerateDraft extends PipelineStage
{
    protected function stage(): string
    {
        return 'generate';
    }

    protected function recordingState(): string
    {
        return 'generating';
    }

    protected function next(): ?string
    {
        return null; // stage 5 (chunk & embed) runs on approval, never on generation (03 §5)
    }

    protected function run(Recording $rec, PipelineJob $job): void
    {
        $t = $rec->transcript()->firstOrFail();
        $segments = $rec->segments()->get();
        $segIn = $segments->map(fn ($s) => ['position' => $s->position, 'ts_start' => (float) $s->ts_start, 'ts_end' => (float) $s->ts_end, 'summary' => $s->summary])->all();
        $lines = Prompts::transcriptLines($t->words ?? []);

        $res = app(LlmDriver::class)->complete(Prompts::GENERATE_SYSTEM, Prompts::generateUser((string) $rec->title, $segIn, $lines), 8192);
        $job->forceFill(['cost_usd' => $res['cost_usd']])->save();

        $d = Json::fromText($res['text']);
        if (! is_array($d) || empty($d['steps']) || ! is_array($d['steps'])) {
            throw new PipelineFailed('A draft could not be generated from this recording. Try again, or record it with clearer narration.');
        }

        $doc = DB::transaction(function () use ($rec, $d, $segments): Document {
            $doc = Document::create([
                'space_id' => $rec->space_id, 'title' => mb_substr((string) ($d['title'] ?: $rec->title ?: 'Untitled procedure'), 0, 250),
                'doc_type' => 'sop', 'owner_id' => $rec->uploaded_by, 'created_by' => $rec->uploaded_by, 'source_recording_id' => $rec->id,
                'content' => Content::merge(Content::empty(), [
                    'purpose' => (string) ($d['purpose'] ?? ''), 'scope' => (string) ($d['scope'] ?? ''),
                    'prerequisites' => array_values(array_filter(array_map('strval', is_array($d['prerequisites'] ?? null) ? $d['prerequisites'] : []))),
                    'outcome' => (string) ($d['outcome'] ?? ''),
                ]),
            ]);
            $pos = 0;
            foreach ($d['steps'] as $s) {
                if (! is_array($s) || trim((string) ($s['instruction'] ?? '')) === '') {
                    continue;
                }
                $seg = isset($s['segment']) ? $segments->firstWhere('position', (int) $s['segment']) : null;
                DocumentStep::create([
                    'document_id' => $doc->id, 'position' => ++$pos,
                    'instruction' => mb_substr((string) $s['instruction'], 0, 5000),
                    'note' => self::note($s), 'expected_result' => isset($s['expected_result']) ? mb_substr((string) $s['expected_result'], 0, 5000) : null,
                    'is_critical' => ! empty($s['warning']),
                    'media_asset_id' => $seg?->frame_asset_id,
                    'source_ts_start' => $seg?->ts_start, 'source_ts_end' => $seg?->ts_end,
                    'verified_at' => null,
                ]);
            }
            if ($pos === 0) {
                throw new PipelineFailed('The generated draft contained no usable steps. Try again with clearer narration.');
            }
            $doc->body_text = DocumentObserver::flatten($doc->load('steps'));
            $doc->save();

            return $doc->refresh();
        });

        $rec->forceFill(['document_id' => $doc->id, 'state' => 'draft_ready'])->save();
        if ($rec->uploaded_by) {
            User::query()->find($rec->uploaded_by)?->notify(new RecordingDraftReadyNotification($doc->title, $rec->id, $doc->id));
        }
        Audit::record('document.generated', 'document', $doc->id, ['recording_id' => $rec->id, 'steps' => $doc->steps()->count(), 'cost_usd' => $res['cost_usd']]);
    }

    /** @param array<string,mixed> $s */
    private static function note(array $s): ?string
    {
        $parts = array_filter([
            ! empty($s['warning']) ? 'Warning: '.$s['warning'] : null,
            ! empty($s['note']) ? (string) $s['note'] : null,
            isset($s['confidence']) && (float) $s['confidence'] < 0.6 ? 'Low confidence — check against the recording.' : null,
        ]);

        return $parts ? mb_substr(implode(' ', $parts), 0, 5000) : null;
    }
}
