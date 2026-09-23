<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentStep;
use App\Observers\DocumentObserver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Steps of the working copy (F1): add, edit, reorder, delete — numbering is
 * always contiguous 1..n after any write. Editing a step counts as verifying
 * it (S9), so verified_at is set on edit.
 */
final class StepController extends Controller
{
    /** GET /v1/documents/{id}/steps */
    public function index(string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('view', $doc);

        return response()->json(['data' => $doc->steps()->get()->map(fn (DocumentStep $s) => $this->present($s))]);
    }

    /** POST /v1/documents/{id}/steps  body { instruction, position?, note?, expected_result?, is_critical?, is_checkpoint? } */
    public function store(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $data = $request->validate($this->rules(true));

        $step = DB::transaction(function () use ($doc, $data, $request): DocumentStep {
            $count = $doc->steps()->count();
            $position = min(max((int) ($data['position'] ?? $count + 1), 1), $count + 1);
            unset($data['position']);
            // Shift later steps down (high to low, to respect the unique key).
            $later = $doc->steps()->where('position', '>=', $position)->reorder('position', 'desc')->get(); // high→low so the unique key never collides
            foreach ($later as $s) {
                $s->position++;
                $s->save();
            }
            $step = DocumentStep::create($data + ['document_id' => $doc->id, 'position' => $position, 'verified_at' => now()]);
            $this->touch($doc, $request);

            return $step;
        });

        return response()->json(['data' => $this->present($step)], 201);
    }

    /** PATCH /v1/documents/{id}/steps/{step_id} */
    public function update(Request $request, string $id, string $stepId): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $step = $doc->steps()->findOrFail($stepId);
        $data = $request->validate($this->rules(false));
        unset($data['position']);
        $step->fill($data + ['verified_at' => now()])->save();
        $this->touch($doc, $request);

        return response()->json(['data' => $this->present($step)]);
    }

    /** POST /v1/documents/{id}/steps/reorder  body { order: [step_id, …] } — must list every step exactly once. */
    public function reorder(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $data = $request->validate(['order' => ['required', 'array', 'min:1'], 'order.*' => ['string', 'size:26', 'distinct']]);

        $steps = $doc->steps()->get()->keyBy('id');
        abort_unless(count($data['order']) === $steps->count() && $steps->keys()->diff($data['order'])->isEmpty(), 422, 'order must contain every step of the document exactly once.');

        DB::transaction(function () use ($steps, $data, $doc, $request): void {
            // Two passes so intermediate states never collide with the unique key.
            foreach ($steps as $s) {
                $s->position = -$s->position;
                $s->save();
            }
            foreach ($data['order'] as $i => $stepId) {
                $s = $steps[$stepId];
                $s->position = $i + 1;
                $s->save();
            }
            $this->touch($doc, $request);
        });

        return response()->json(['data' => $doc->steps()->get()->map(fn (DocumentStep $s) => $this->present($s))]);
    }

    /** DELETE /v1/documents/{id}/steps/{step_id} — renumbering is automatic. */
    public function destroy(Request $request, string $id, string $stepId): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $step = $doc->steps()->findOrFail($stepId);

        DB::transaction(function () use ($doc, $step, $request): void {
            $pos = $step->position;
            $step->delete();
            foreach ($doc->steps()->where('position', '>', $pos)->orderBy('position')->get() as $s) {
                $s->position--;
                $s->save();
            }
            $this->touch($doc, $request);
        });

        return response()->json(['data' => ['id' => $stepId, 'deleted' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        return [
            'instruction' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:5000'],
            'position' => ['sometimes', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'expected_result' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_critical' => ['sometimes', 'boolean'],
            'is_checkpoint' => ['sometimes', 'boolean'],
            'media_asset_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }

    /** Step text is part of body_text; an approved document edited via steps becomes a draft revision (FR-408). */
    private function touch(Document $doc, Request $request): void
    {
        $doc->unsetRelation('steps');
        $doc->body_text = DocumentObserver::flatten($doc);
        if ($doc->state === 'approved') {
            $doc->state = 'draft';
        }
        $doc->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DocumentStep $s): array
    {
        return $s->only(['id', 'position', 'instruction', 'note', 'expected_result', 'is_critical', 'is_checkpoint', 'media_asset_id', 'source_ts_start', 'source_ts_end', 'verified_at']);
    }
}
