<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Document;
use App\Models\DocumentRead;
use App\Models\Space;
use Illuminate\Http\JsonResponse;

/**
 * S20 analytics (FR-908, FR-909): documents by state, past review date by
 * space, approval throughput, chat volume, most-read documents, helpful rate.
 * Aggregations are done in PHP over scoped model queries — the counts are
 * small per workspace and this keeps the raw-query guard intact.
 */
final class AnalyticsController extends Controller
{
    /** GET /v1/analytics/overview — admin. Windows: last 30 days. */
    public function overview(): JsonResponse
    {
        $this->authorize('workspace-admin');
        $since = now()->subDays(30);

        $byState = array_fill_keys(Document::STATES, 0);
        foreach (Document::query()->get(['state']) as $d) {
            $byState[$d->state]++;
        }

        $spaces = Space::query()->get(['id', 'name'])->keyBy('id');
        $overdue = [];
        foreach (Document::query()->where('state', 'approved')->where('review_due_at', '<=', now())->get(['space_id']) as $d) {
            $overdue[$d->space_id] = ($overdue[$d->space_id] ?? 0) + 1;
        }
        $pastReview = collect($overdue)->map(fn (int $n, string $sid) => ['space_id' => $sid, 'space' => $spaces->get($sid)?->name ?? '—', 'count' => $n])->sortByDesc('count')->values()->all();

        $approvals = Approval::query()->where('to_state', 'approved')->where('created_at', '>=', $since)->count();

        $questions = ChatMessage::query()->where('role', 'user')->where('created_at', '>=', $since)->get(['session_id', 'created_at']);
        $askers = ChatSession::query()->whereIn('id', $questions->pluck('session_id')->unique())->get(['user_id'])->pluck('user_id')->unique()->count();
        $answers = ChatMessage::query()->where('role', 'assistant')->where('created_at', '>=', $since)->get(['refused', 'rated_helpful']);
        $rated = $answers->whereNotNull('rated_helpful');
        $helpfulRate = $rated->count() ? round($rated->where('rated_helpful', true)->count() / $rated->count() * 100) : null;
        $refusedRate = $answers->count() ? round($answers->where('refused', true)->count() / $answers->count() * 100) : null;

        $readCounts = [];
        foreach (DocumentRead::query()->where('read_on', '>=', $since->toDateString())->get(['document_id']) as $r) {
            $readCounts[$r->document_id] = ($readCounts[$r->document_id] ?? 0) + 1;
        }
        arsort($readCounts);
        $top = array_slice($readCounts, 0, 10, true);
        $titles = Document::query()->whereIn('id', array_keys($top))->get(['id', 'title'])->keyBy('id');
        $mostRead = collect($top)->map(fn (int $n, string $id) => ['document_id' => $id, 'title' => $titles->get($id)?->title ?? '—', 'reads' => $n])->values()->all();

        return response()->json(['data' => [
            'window_days' => 30,
            'documents_by_state' => $byState,
            'past_review_by_space' => $pastReview,
            'approvals_30d' => $approvals,
            'chat' => ['questions_30d' => $questions->count(), 'unique_askers_30d' => $askers, 'helpful_rate_pct' => $helpfulRate, 'refused_rate_pct' => $refusedRate],
            'most_read' => $mostRead,
        ]]);
    }
}
