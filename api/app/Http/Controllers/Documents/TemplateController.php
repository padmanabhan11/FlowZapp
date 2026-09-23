<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Audit\Audit;
use App\Documents\Content;
use App\Documents\Templates;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentStep;
use App\Models\DocumentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** B5-T3 — workspace templates, saved from an existing document. Built-ins are listed first and cannot be deleted. */
final class TemplateController extends Controller
{
    /** GET /v1/templates */
    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (array $t) => [
            'id' => $t['id'], 'name' => $t['name'], 'doc_type' => $t['doc_type'], 'description' => $t['description'],
            'custom' => $t['custom'] ?? false, 'created_by' => $t['created_by'] ?? null, 'step_count' => count($t['steps'] ?? []),
        ], Templates::forWorkspace())]);
    }

    /** POST /v1/templates  body { document_id, name, description? } — editor+ in the workspace who can see the document. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('workspace-editor');
        $data = $request->validate(['document_id' => ['required', 'string', 'size:26'], 'name' => ['required', 'string', 'min:2', 'max:120'], 'description' => ['nullable', 'string', 'max:500']]);
        $doc = Document::query()->findOrFail($data['document_id']);
        $this->authorize('view', $doc);
        abort_if(DocumentTemplate::query()->where('name', $data['name'])->exists(), 422, 'A template with that name already exists.');

        // Strip asset references: a template must not carry another document's images (their access follows that document).
        $content = Content::merge(Content::empty(), $doc->content ?? []);
        $content['blocks'] = array_values(array_filter($content['blocks'], fn (array $b) => ! in_array($b['type'] ?? '', ['image', 'video', 'file'], true)));
        $steps = $doc->steps()->get()->map(fn (DocumentStep $s) => array_filter([
            'instruction' => $s->instruction, 'note' => $s->note, 'expected_result' => $s->expected_result,
            'is_critical' => $s->is_critical ?: null, 'is_checkpoint' => $s->is_checkpoint ?: null,
        ], fn ($v) => $v !== null))->values()->all();

        $t = DocumentTemplate::create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'doc_type' => $doc->doc_type, 'content' => $content, 'steps' => $steps, 'created_by' => $request->user()->id]);
        Audit::record('template.created', 'template', $t->id, ['from' => $doc->id, 'name' => $t->name]);

        return response()->json(['data' => Templates::present($t->load('creator:id,name'))], 201);
    }

    /** DELETE /v1/templates/{id} — the creator or an admin. Documents already created from it are unaffected. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $t = DocumentTemplate::query()->findOrFail($id);
        $isAdmin = $request->attributes->get('workspace_role') === 'admin';
        abort_unless($isAdmin || $t->created_by === $request->user()->id, 403, 'Not permitted.');
        $t->delete();
        Audit::record('template.deleted', 'template', $id, ['name' => $t->name]);

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }
}
