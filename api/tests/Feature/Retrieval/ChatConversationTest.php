<?php

declare(strict_types=1);

namespace Tests\Feature\Retrieval;

use App\Ai\FakeLlm;
use App\Ai\LlmDriver;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Retrieval\Conversation;
use App\Retrieval\Embeddings;
use App\Retrieval\GapClusters;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/**
 * H4-T2 multi-turn context retention, plus FR-614 semantic knowledge gaps.
 * The fake model records every prompt, so each test can check exactly what
 * the assistant was shown on the follow-up turn.
 */
final class ChatConversationTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    private Workspace $ws;

    private User $admin;

    private User $approver;

    private User $reader;

    private string $general;

    private string $finance;

    private FakeLlm $llm;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [$this->ws, $this->admin] = $this->makeWorkspace('acme', 'team');
        $cw = app(CurrentWorkspace::class);
        $this->general = $cw->runAs($this->ws->id, fn () => Space::query()->firstOrFail()->id);
        $this->finance = $cw->runAs($this->ws->id, fn () => Space::create(['name' => 'Finance', 'created_by' => $this->admin->id])->id);
        $this->approver = $this->addMember($this->ws, 'ap@example.test', 'approver');
        $this->reader = $this->addMember($this->ws, 'reader@example.test', 'reader');
        $cw->runAs($this->ws->id, function (): void {
            foreach ([$this->general, $this->finance] as $sid) {
                SpaceMember::create(['space_id' => $sid, 'user_id' => $this->approver->id, 'role' => 'approver']);
                SpaceMember::create(['space_id' => $sid, 'user_id' => $this->reader->id, 'role' => 'reader']);
            }
        });
        $this->llm = new class extends FakeLlm
        {
            /** @var list<array{system: string, user: string}> */
            public array $calls = [];

            public function complete(string $system, string $user, int $maxTokens = 4096, float $temperature = 0.0): array
            {
                $this->calls[] = ['system' => $system, 'user' => $user];

                return parent::complete($system, $user, $maxTokens, $temperature);
            }

            /** @return list<string> */
            public function prompts(string $marker): array
            {
                return array_values(array_map(fn ($c) => $c['user'], array_filter($this->calls, fn ($c) => str_contains($c['system'], $marker))));
            }
        };
        $this->app->instance(LlmDriver::class, $this->llm);
        FakeLlm::$responses = ['[answer]' => (string) json_encode(['answer' => 'Per the procedure [1].', 'citations' => [1], 'refused' => false])];
    }

    /** @return array<string,string> */
    private function h(): array
    {
        return $this->wsHeaders($this->ws);
    }

    private function publish(string $title, string $purpose, array $steps, string $spaceId): string
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/documents', ['space_id' => $spaceId, 'title' => $title], $this->h())->json('data.id');
        $this->actingAs($this->admin)->patchJson("/api/v1/documents/{$id}", ['content' => ['purpose' => $purpose]], $this->h())->assertOk();
        foreach ($steps as $s) {
            $this->actingAs($this->admin)->postJson("/api/v1/documents/{$id}/steps", ['instruction' => $s], $this->h())->assertStatus(201);
        }
        $this->actingAs($this->admin)->postJson("/api/v1/documents/{$id}/submit", [], $this->h())->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/documents/{$id}/approve", [], $this->h())->assertOk();

        return $id;
    }

    private function ask(string $session, string $q, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->reader)->postJson("/api/v1/chat/sessions/{$session}/messages", ['content' => $q], $this->h())->assertOk()->json('data');
    }

    private function openSession(?User $as = null, array $body = []): string
    {
        return $this->actingAs($as ?? $this->reader)->postJson('/api/v1/chat/sessions', $body, $this->h())->assertStatus(201)->json('data.id');
    }

    public function test_a_follow_up_is_retrieved_as_a_standalone_query_and_answered_with_the_conversation(): void
    {
        $this->publish('Refund Policy', 'How we give customers their money back.', ['Refunds within 30 days are approved by support.', 'Refunds after 60 days need the finance director.'], $this->general);
        $this->publish('Client Onboarding', 'Bring a new client in.', ['Create the client folder.'], $this->general);
        $s = $this->openSession();

        $first = $this->ask($s, 'How do refunds work?');
        $this->assertFalse($first['refused']);
        $this->assertSame([], $this->llm->prompts('[condense]'), 'the first question needs no rewrite');

        FakeLlm::$responses['[condense]'] = '{"query":"who approves refunds after 60 days"}';
        $second = $this->ask($s, 'and after 60 days?');

        $condense = $this->llm->prompts('[condense]');
        $this->assertCount(1, $condense);
        $this->assertStringContainsString('- How do refunds work?', $condense[0]);
        $this->assertStringContainsString('Latest message: and after 60 days?', $condense[0]);

        $answer = $this->llm->prompts('[answer]')[1];
        $this->assertStringContainsString("Conversation so far:\nUSER: How do refunds work?\nASSISTANT: Per the procedure [1].", $answer);
        $this->assertStringContainsString('Question: and after 60 days?', $answer, 'the answer addresses the question as asked');
        $this->assertStringContainsString('finance director', $answer, 'the rewritten query retrieved the right step');
        $this->assertSame('Refund Policy', $second['citations'][0]['title']);
    }

    public function test_an_unusable_rewrite_falls_back_to_carrying_the_previous_question(): void
    {
        FakeLlm::$responses['[condense]'] = 'not json at all';
        $r = app(Conversation::class)->standaloneQuery('and after 60 days?', [['role' => 'user', 'content' => 'How do refunds work?'], ['role' => 'assistant', 'content' => 'Per the procedure.']]);
        $this->assertSame('How do refunds work? and after 60 days?', $r['query']);
        $this->assertTrue($r['rewritten']);

        $this->assertSame('Where is the VPN guide?', app(Conversation::class)->standaloneQuery('Where is the VPN guide?', [])['query']);
    }

    public function test_history_is_bounded_by_turns_and_characters(): void
    {
        config(['flowzapp.chat.history_turns' => 4, 'flowzapp.chat.history_chars' => 2500, 'flowzapp.chat.history_turn_chars' => 1000]);
        $s = app(CurrentWorkspace::class)->runAs($this->ws->id, function () {
            $s = ChatSession::create(['user_id' => $this->reader->id]);
            for ($i = 1; $i <= 6; $i++) {
                ChatMessage::create(['session_id' => $s->id, 'role' => 'user', 'content' => "question {$i}"]);
                ChatMessage::create(['session_id' => $s->id, 'role' => 'assistant', 'content' => "refused {$i} ".str_repeat('x', 3000), 'refused' => true]);
            }

            return $s;
        });

        $h = app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => app(Conversation::class)->history($s, $this->reader)['history']);
        // Newest first until the budget: answer 6 (1000) + question 6 + answer 5 (1000) + question 5 = 2020; answer 4 would pass 2500.
        $this->assertSame(['question 5', 'refused 5', 'question 6', 'refused 6'], array_map(fn ($m) => implode(' ', array_slice(explode(' ', $m['content']), 0, 2)), $h));
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column($h, 'role'), 'oldest first, as the model reads it');
        foreach ($h as $m) {
            $this->assertLessThanOrEqual(1000, mb_strlen($m['content']), 'each turn is truncated');
        }
    }

    public function test_an_earlier_answer_is_not_replayed_once_the_asker_loses_access_to_its_source(): void
    {
        $this->publish('Board Compensation', 'How directors are paid.', ['Directors receive quarterly equity grants.'], $this->finance);
        $this->publish('Refund Policy', 'How we give customers their money back.', ['Check the order date.'], $this->general);
        $s = $this->openSession();

        FakeLlm::$responses['[answer]'] = (string) json_encode(['answer' => 'Directors receive quarterly equity grants [1].', 'citations' => [1], 'refused' => false]);
        $first = $this->ask($s, 'How are directors paid?');
        $this->assertSame('Board Compensation', $first['citations'][0]['title']);

        // The reader leaves Finance.
        app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => SpaceMember::query()->where('space_id', $this->finance)->where('user_id', $this->reader->id)->delete());

        FakeLlm::$responses['[answer]'] = (string) json_encode(['answer' => 'Check the order date [1].', 'citations' => [1], 'refused' => false]);
        FakeLlm::$responses['[condense]'] = '{"query":"how are refunds checked"}';
        $this->ask($s, 'And how are refunds checked?');

        $answer = $this->llm->prompts('[answer]')[1];
        $this->assertStringNotContainsString('equity', $answer, 'the transcript must not carry content the asker can no longer see');
        $this->assertStringContainsString('USER: How are directors paid?', $answer, 'their own question stays');
        $h = app(CurrentWorkspace::class)->runAs($this->ws->id, fn () => app(Conversation::class)->history(ChatSession::query()->findOrFail($s), $this->reader));
        $this->assertSame(1, $h['dropped_for_access']);
    }

    public function test_a_session_scoped_to_one_document_stays_scoped_across_turns_and_is_private(): void
    {
        $refunds = $this->publish('Refund Policy', 'How we give customers their money back.', ['Refunds after 60 days need the finance director.'], $this->general);
        $this->publish('Client Onboarding', 'Bring a new client in.', ['Create the client folder.'], $this->general);
        $s = $this->openSession(null, ['scope_document_id' => $refunds]);

        $this->ask($s, 'What does this cover?');
        FakeLlm::$responses['[condense]'] = '{"query":"create the client folder"}';
        $second = $this->ask($s, 'and the client folder?');
        foreach ($second['citations'] as $c) {
            $this->assertSame($refunds, $c['document_id']);
        }
        foreach ($this->llm->prompts('[answer]') as $prompt) {
            $this->assertStringNotContainsString('Client Onboarding', $prompt, 'a scoped session never retrieves outside its document');
        }

        // Someone else cannot read or continue my conversation.
        $this->actingAs($this->approver)->getJson("/api/v1/chat/sessions/{$s}/messages", $this->h())->assertStatus(403);
        $this->actingAs($this->approver)->postJson("/api/v1/chat/sessions/{$s}/messages", ['content' => 'hi'], $this->h())->assertStatus(403);

        $msgs = $this->actingAs($this->reader)->getJson("/api/v1/chat/sessions/{$s}/messages", $this->h())->json('data');
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column($msgs, 'role'), 'turns come back in order');
    }

    public function test_knowledge_gaps_group_refused_questions_by_meaning(): void
    {
        $emb = new class implements Embeddings
        {
            public function model(): string
            {
                return 'stub';
            }

            public function dimensions(): int
            {
                return 2;
            }

            public function embed(array $texts): array
            {
                return array_map(fn ($t) => str_contains($t, 'taxi') || str_contains($t, 'cab') ? [1.0, 0.05] : [0.0, 1.0], $texts);
            }
        };
        $t = Carbon::parse('2026-09-20 10:00:00');
        $groups = (new GapClusters($emb))->group([
            ['question' => 'Can I claim a cab?', 'asked_at' => $t->copy()->addHours(3)],
            ['question' => 'How do I expense a taxi?', 'asked_at' => $t->copy()->addHours(2)],
            ['question' => 'how do I expense a taxi', 'asked_at' => $t->copy()->addHour()],
            ['question' => 'What is the parental leave policy?', 'asked_at' => $t],
        ]);

        $this->assertCount(2, $groups);
        $this->assertSame(3, $groups[0]['count']);
        $this->assertSame('How do I expense a taxi?', $groups[0]['question'], 'labelled with the most-asked phrasing');
        $this->assertSame(['Can I claim a cab?', 'How do I expense a taxi?'], $groups[0]['variants']);
        $this->assertTrue($groups[0]['last_asked_at']->eq($t->copy()->addHours(3)));
        $this->assertSame(1, $groups[1]['count']);
    }
}
