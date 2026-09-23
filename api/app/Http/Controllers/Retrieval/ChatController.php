<?php

declare(strict_types=1);

namespace App\Http\Controllers\Retrieval;

use App\Audit\Audit;
use App\Billing\Usage;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Document;
use App\Retrieval\Answerer;
use App\Retrieval\Retriever;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat (05 "Chat"; S15). Answers come only from approved content the asking
 * user can access; every non-refused answer carries at least one citation;
 * refusal is a designed state. Streams over SSE when the client asks for
 * text/event-stream (retrieval → token* → done); otherwise returns JSON.
 */
final class ChatController extends Controller
{
    public function __construct(private readonly Retriever $retriever, private readonly Answerer $answerer, private readonly Usage $usage) {}

    /** POST /v1/chat/sessions  body { scope_document_id?, title? } */
    public function createSession(Request $request): JsonResponse
    {
        $data = $request->validate(['scope_document_id' => ['nullable', 'string', 'size:26'], 'title' => ['nullable', 'string', 'max:250']]);
        if (! empty($data['scope_document_id'])) {
            $this->authorize('view', Document::query()->findOrFail($data['scope_document_id']));
        }
        $s = ChatSession::create(['user_id' => $request->user()->id, 'title' => $data['title'] ?? null, 'scope_document_id' => $data['scope_document_id'] ?? null]);

        return response()->json(['data' => $this->session($s)], 201);
    }

    /** GET /v1/chat/sessions — caller's sessions. */
    public function sessions(Request $request): JsonResponse
    {
        $rows = ChatSession::query()->where('user_id', $request->user()->id)->orderByDesc('updated_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn (ChatSession $s) => $this->session($s))]);
    }

    /** GET /v1/chat/sessions/{id}/messages */
    public function messages(Request $request, string $id): JsonResponse
    {
        $s = $this->own($request, $id);

        return response()->json(['data' => $s->messages()->get()->map(fn (ChatMessage $m) => $this->message($m))]);
    }

    /** POST /v1/chat/sessions/{id}/messages  body { content } — SSE or JSON. */
    public function ask(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $s = $this->own($request, $id);
        $data = $request->validate(['content' => ['required', 'string', 'min:1', 'max:2000']]);
        $this->usage->assert('chat_queries_per_day');   // Team-only (402) and the daily cap (429)
        $question = trim($data['content']);
        $started = microtime(true);

        ChatMessage::create(['session_id' => $s->id, 'role' => 'user', 'content' => $question]);
        if ($s->title === null) {
            $s->forceFill(['title' => mb_substr($question, 0, 80)])->save();
        }
        $history = $s->messages()->get()->map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content])->slice(0, -1)->values()->all();

        $scope = $this->retriever->scopeFor($request->user(), $request->attributes->get('workspace_role') === 'admin');
        $filters = $s->scope_document_id ? ['document_id' => $s->scope_document_id] : [];
        $hits = $this->retriever->retrieve($question, $scope, $filters);
        $answer = $this->answerer->answer($question, $hits, $history);
        $latency = (int) round((microtime(true) - $started) * 1000);

        $msg = ChatMessage::create(['session_id' => $s->id, 'role' => 'assistant', 'content' => $answer['text'], 'citations' => $answer['citations'], 'refused' => $answer['refused'], 'latency_ms' => $latency]);
        $s->touch();
        if ($answer['refused']) {
            Audit::record('chat.refused', 'chat_message', $msg->id, ['question' => mb_substr($question, 0, 200)]);
        }

        if (! str_contains((string) $request->header('Accept'), 'text/event-stream')) {
            return response()->json(['data' => $this->message($msg)]);
        }

        return response()->stream(function () use ($answer, $msg, $latency): void {
            $emit = function (string $event, array $payload): void {
                echo "event: {$event}\ndata: ".json_encode($payload)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            $emit('retrieval', ['citations' => $answer['citations']]);
            foreach (preg_split('/(?<=\s)/u', $answer['text']) ?: [] as $piece) {
                if ($piece !== '') {
                    $emit('token', ['text' => $piece]);
                }
            }
            $emit('done', ['message_id' => $msg->id, 'refused' => $answer['refused'], 'latency_ms' => $latency, 'citations' => $answer['citations']]);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    /** POST /v1/chat/messages/{id}/rating  body { helpful } */
    public function rate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['helpful' => ['required', 'boolean']]);
        $m = ChatMessage::query()->findOrFail($id);
        $this->own($request, $m->session_id);
        $m->forceFill(['rated_helpful' => (bool) $data['helpful']])->save();

        return response()->json(['data' => ['id' => $m->id, 'rated_helpful' => $m->rated_helpful]]);
    }

    /** GET /v1/analytics/knowledge-gaps — refused questions ranked by frequency (H6, S20). Grouped by normalised text; semantic grouping is FR-614 (C). */
    public function gaps(Request $request): JsonResponse
    {
        $this->authorize('workspace-admin');
        $refused = ChatMessage::query()->where('role', 'assistant')->where('refused', true)->orderByDesc('created_at')->limit(500)->get();
        $users = ChatMessage::query()->where('role', 'user')->whereIn('session_id', $refused->pluck('session_id')->unique())->orderBy('created_at')->get()->groupBy('session_id');
        $rows = $refused->map(function (ChatMessage $a) use ($users) {
            // The question is the last user message in the session before this refusal.
            return $users->get($a->session_id, collect())->filter(fn (ChatMessage $u) => $u->created_at <= $a->created_at)->last();
        })->filter();
        $groups = [];
        foreach ($rows as $m) {
            $key = preg_replace('/[^a-z0-9 ]/', '', mb_strtolower($m->content)) ?? $m->content;
            $key = trim(preg_replace('/\s+/', ' ', $key) ?? '');
            $groups[$key] ??= ['question' => $m->content, 'count' => 0, 'last_asked_at' => $m->created_at];
            $groups[$key]['count']++;
        }
        usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return response()->json(['data' => $groups]);   // usort already re-indexed
    }

    private function own(Request $request, string $sessionId): ChatSession
    {
        $s = ChatSession::query()->findOrFail($sessionId);
        abort_unless($s->user_id === $request->user()->id, 403, 'Not permitted.');

        return $s;
    }

    /**
     * @return array<string,mixed>
     */
    private function session(ChatSession $s): array
    {
        return $s->only(['id', 'title', 'scope_document_id', 'created_at', 'updated_at']);
    }

    /**
     * @return array<string,mixed>
     */
    private function message(ChatMessage $m): array
    {
        return $m->only(['id', 'role', 'content', 'citations', 'refused', 'latency_ms', 'rated_helpful', 'created_at']);
    }
}
