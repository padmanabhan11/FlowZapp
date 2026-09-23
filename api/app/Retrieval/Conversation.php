<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Ai\Json;
use App\Ai\LlmDriver;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Document;
use App\Models\User;

/**
 * Multi-turn context for the assistant (H4; FR-605 follow-up questions).
 *
 *  - history(): the turns the model may see, newest last, bounded by
 *    flowzapp.chat.history_turns and history_chars. An earlier answer is only
 *    replayed if the asker can still see every document it cited — access
 *    revoked mid-conversation must not leak back through the transcript
 *    (non-negotiable 4). Refusals and questions are always kept.
 *  - standaloneQuery(): a follow-up like "and after 60 days?" retrieves
 *    nothing on its own, so it is rewritten into a self-contained search
 *    query from the recent questions ([condense] prompt). Retrieval uses the
 *    rewrite; the answer is still written to the question as asked. If the
 *    model output is unusable, the previous question is prepended instead.
 */
final class Conversation
{
    public const CONDENSE_SYSTEM = <<<'TXT'
[condense] You rewrite the user's latest message into one standalone search query for a company's procedure library, using the earlier questions only to resolve references ("it", "that step", "what about after 60 days?").
Keep the user's own words and every specific (names, numbers, systems); add nothing that was not said; if the latest message already stands alone, return it unchanged.
Output JSON only: {"query":"…"}
TXT;

    public function __construct(private readonly LlmDriver $llm) {}

    /**
     * @return array{history: list<array{role: string, content: string}>, dropped_for_access: int}
     */
    public function history(ChatSession $session, User $user, ?string $beforeMessageId = null): array
    {
        $turns = (int) config('flowzapp.chat.history_turns', 6);
        $budget = (int) config('flowzapp.chat.history_chars', 6000);
        $perTurn = (int) config('flowzapp.chat.history_turn_chars', 1500);

        $q = ChatMessage::query()->where('session_id', $session->id)->orderByDesc('id');
        if ($beforeMessageId !== null) {
            $q->where('id', '<', $beforeMessageId);
        }
        $messages = $q->limit($turns * 2)->get();

        $cited = $messages->flatMap(fn (ChatMessage $m) => array_column($m->citations ?? [], 'document_id'))->unique()->values();
        $visible = $cited->isEmpty() ? collect() : Document::query()->whereIn('id', $cited)->live()->get()
            ->filter(fn (Document $d) => $user->can('view', $d))->keyBy('id');

        $out = [];
        $used = 0;
        $dropped = 0;
        foreach ($messages as $m) {
            if ($m->role === 'assistant' && ! $m->refused) {
                $ids = array_column($m->citations ?? [], 'document_id');
                if ($ids === [] || array_diff($ids, $visible->keys()->all()) !== []) {
                    $dropped++;

                    continue;   // cites something the asker can no longer see: never replay it
                }
            }
            $text = mb_substr($m->content, 0, $perTurn);
            if ($used + mb_strlen($text) > $budget) {
                break;
            }
            $used += mb_strlen($text);
            array_unshift($out, ['role' => $m->role, 'content' => $text]);
            if (count($out) >= $turns) {
                break;
            }
        }

        return ['history' => $out, 'dropped_for_access' => $dropped];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{query: string, rewritten: bool, cost_usd: float}
     */
    public function standaloneQuery(string $question, array $history): array
    {
        $previous = array_values(array_filter($history, fn ($m) => $m['role'] === 'user'));
        if ($previous === []) {
            return ['query' => $question, 'rewritten' => false, 'cost_usd' => 0.0];
        }
        $recent = array_slice($previous, -3);
        $user = "Earlier questions:\n".implode("\n", array_map(fn ($m) => '- '.mb_substr($m['content'], 0, 300), $recent))."\n\nLatest message: {$question}";
        $res = $this->llm->complete(self::CONDENSE_SYSTEM, $user, 200, 0.0);
        $d = Json::fromText($res['text']);
        $query = is_array($d) ? trim((string) ($d['query'] ?? '')) : '';
        if ($query === '' || mb_strlen($query) > 500) {
            $query = trim(end($recent)['content'].' '.$question);   // fallback: carry the previous question along
        }

        return ['query' => $query, 'rewritten' => $query !== $question, 'cost_usd' => (float) $res['cost_usd']];
    }
}
