<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Json;
use App\Ai\LlmDriver;
use App\Ai\Prompts;
use App\Audit\Audit;
use App\Documents\Content;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * FR-801..805 — content assistance. Every call returns a proposal; nothing
 * here mutates the document (FR-805, doc 05: "the server never mutates a
 * document from an AI call"). Applying a rewrite is an ordinary PATCH from the
 * client. Translation is the one exception by design: it creates a *new*
 * linked draft (FR-803), leaving the source untouched.
 */
final class AiController extends Controller
{
    public const LANGUAGES = ['en', 'de', 'fr', 'es', 'pt', 'nl', 'it'];

    public function __construct(private readonly LlmDriver $llm) {}

    /**
     * POST /v1/ai/rewrite  body { document_id, scope: "document"|"blocks"|"steps"|"selection", keys?: [], text? }
     * Returns { original: {key: text}, proposed: {key: text}, changed: [keys] }.
     */
    public function rewrite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_id' => ['required', 'string', 'size:26'],
            'scope' => ['required', Rule::in(['document', 'steps', 'blocks', 'selection'])],
            'keys' => ['nullable', 'array', 'max:200'], 'keys.*' => ['string', 'max:80'],
            'text' => ['nullable', 'string', 'max:5000'],
        ]);
        $doc = Document::query()->findOrFail($data['document_id']);
        $this->authorize('edit', $doc);

        $original = $data['scope'] === 'selection'
            ? ['selection' => (string) ($data['text'] ?? '')]
            : $this->collect($doc, $data['scope'], $data['keys'] ?? null);
        abort_if($original === [], 422, 'Nothing to rewrite.');

        $res = $this->llm->complete(Prompts::REWRITE_SYSTEM, json_encode($original, JSON_UNESCAPED_UNICODE) ?: '{}', 4096, 0.2);
        $proposed = Json::fromText($res['text']);
        if (! is_array($proposed)) {
            throw new HttpException(502, 'The rewrite could not be produced. Try again.');
        }
        $proposed = array_intersect_key(array_map('strval', $proposed), $original) + $original;   // never invent keys, never drop any
        $changed = array_keys(array_filter($proposed, fn ($v, $k) => trim($v) !== trim($original[$k]), ARRAY_FILTER_USE_BOTH));
        Audit::record('ai.rewrite_proposed', 'document', $doc->id, ['scope' => $data['scope'], 'changed' => count($changed), 'cost_usd' => $res['cost_usd']]);

        return response()->json(['data' => ['original' => $original, 'proposed' => $proposed, 'changed' => array_values($changed), 'cost_usd' => $res['cost_usd']]]);
    }

    /** POST /v1/ai/translate  body { document_id, target_language } → 201 with the new linked draft. */
    public function translate(Request $request): JsonResponse
    {
        $data = $request->validate(['document_id' => ['required', 'string', 'size:26'], 'target_language' => ['required', Rule::in(self::LANGUAGES)]]);
        $src = Document::query()->findOrFail($data['document_id']);
        $this->authorize('edit', $src);
        abort_if($src->language === $data['target_language'], 422, 'The document is already in that language.');
        $existing = Document::query()->where('translation_of', $src->id)->where('language', $data['target_language'])->first();
        abort_if($existing !== null, 409, "A {$data['target_language']} translation already exists.");

        $texts = $this->collect($src, 'document') + ['title' => $src->title];
        $res = $this->llm->complete(Prompts::TRANSLATE_SYSTEM, json_encode(['target_language' => $data['target_language'], 'texts' => $texts], JSON_UNESCAPED_UNICODE) ?: '{}', 8192, 0.1);
        $out = Json::fromText($res['text']);
        if (! is_array($out)) {
            throw new HttpException(502, 'The translation could not be produced. Try again.');
        }
        $t = array_map('strval', array_intersect_key($out, $texts)) + $texts;

        $copy = DB::transaction(function () use ($src, $t, $data, $request): Document {
            $content = $src->content ?? Content::empty();
            foreach (['purpose', 'scope', 'outcome'] as $k) {
                if (isset($t["section:$k"])) {
                    $content[$k] = $t["section:$k"];
                }
            }
            $content['prerequisites'] = array_values(array_map(fn ($i) => $t["prereq:$i"] ?? $content['prerequisites'][$i] ?? '', array_keys($content['prerequisites'] ?? [])));
            $content['blocks'] = array_map(function (array $b) use ($t): array {
                if (isset($t['block:'.$b['id']])) {
                    $b['text'] = $t['block:'.$b['id']];
                }
                foreach ($b['items'] ?? [] as $i => $item) {
                    $k = 'block:'.$b['id'].':'.$i;
                    if (isset($t[$k])) {
                        $b['items'][$i] = is_array($item) ? ['text' => $t[$k]] + $item : $t[$k];
                    }
                }

                return $b;
            }, $content['blocks'] ?? []);

            $copy = Document::create([
                'space_id' => $src->space_id, 'folder_id' => $src->folder_id, 'title' => $t['title'], 'doc_type' => $src->doc_type,
                'owner_id' => $src->owner_id, 'created_by' => $request->user()->id, 'content' => $content,
                'language' => $data['target_language'], 'translation_of' => $src->id, 'requires_ack' => false,
            ]);
            foreach ($src->steps()->get() as $s) {
                DocumentStep::create($s->only(['position', 'is_critical', 'is_checkpoint', 'media_asset_id', 'source_ts_start', 'source_ts_end']) + [
                    'document_id' => $copy->id, 'version_id' => Document::WORKING,
                    'instruction' => $t['step:'.$s->id.':instruction'] ?? $s->instruction,
                    'note' => isset($t['step:'.$s->id.':note']) ? $t['step:'.$s->id.':note'] : $s->note,
                    'expected_result' => isset($t['step:'.$s->id.':expected_result']) ? $t['step:'.$s->id.':expected_result'] : $s->expected_result,
                    'verified_at' => $s->verified_at,
                ]);
            }

            return $copy->refresh();
        });
        Audit::record('ai.translated', 'document', $copy->id, ['source' => $src->id, 'language' => $data['target_language'], 'cost_usd' => $res['cost_usd']]);

        return response()->json(['data' => ['id' => $copy->id, 'title' => $copy->title, 'language' => $copy->language, 'translation_of' => $src->id, 'state' => $copy->state]], 201);
    }

    /** POST /v1/ai/suggest-title  body { document_id } → { titles: [] } */
    public function suggestTitle(Request $request): JsonResponse
    {
        $data = $request->validate(['document_id' => ['required', 'string', 'size:26']]);
        $doc = Document::query()->findOrFail($data['document_id']);
        $this->authorize('edit', $doc);
        $steps = $doc->steps()->get()->map(fn (DocumentStep $s) => $s->instruction)->take(12)->implode("\n");
        $res = $this->llm->complete(Prompts::TITLE_SYSTEM, "Purpose: {$doc->content['purpose']}\nSteps:\n{$steps}", 300, 0.5);
        $out = Json::fromText($res['text']);
        $titles = array_values(array_filter(array_map('strval', $out['titles'] ?? []), fn ($t) => $t !== ''));

        return response()->json(['data' => ['titles' => array_slice($titles, 0, 5)]]);
    }

    /**
     * Flatten the editable text of a document into key => text.
     * Keys: section:purpose|scope|outcome, prereq:N, step:<id>:instruction|note|expected_result, block:<id>[:N].
     *
     * @param  list<string>|null  $keys
     * @return array<string, string>
     */
    private function collect(Document $doc, string $scope, ?array $keys = null): array
    {
        $out = [];
        $c = $doc->content ?? Content::empty();
        if (in_array($scope, ['document', 'blocks'], true)) {
            if ($scope === 'document') {
                foreach (['purpose', 'scope', 'outcome'] as $k) {
                    if (trim((string) ($c[$k] ?? '')) !== '') {
                        $out["section:$k"] = (string) $c[$k];
                    }
                }
                foreach ($c['prerequisites'] ?? [] as $i => $p) {
                    $out["prereq:$i"] = (string) $p;
                }
            }
            foreach ($c['blocks'] ?? [] as $b) {
                if (in_array($b['type'] ?? '', ['code', 'divider', 'image', 'video', 'file', 'link', 'table'], true)) {
                    continue;
                }
                if (isset($b['text']) && trim((string) $b['text']) !== '') {
                    $out['block:'.$b['id']] = (string) $b['text'];
                }
                foreach ($b['items'] ?? [] as $i => $item) {
                    $out['block:'.$b['id'].':'.$i] = is_array($item) ? (string) ($item['text'] ?? '') : (string) $item;
                }
            }
        }
        if (in_array($scope, ['document', 'steps'], true)) {
            foreach ($doc->steps()->get() as $s) {
                $out["step:{$s->id}:instruction"] = $s->instruction;
                if ($s->note) {
                    $out["step:{$s->id}:note"] = $s->note;
                }
                if ($s->expected_result) {
                    $out["step:{$s->id}:expected_result"] = $s->expected_result;
                }
            }
        }
        if ($keys !== null) {
            $out = array_intersect_key($out, array_flip($keys));
        }

        return $out;
    }
}
