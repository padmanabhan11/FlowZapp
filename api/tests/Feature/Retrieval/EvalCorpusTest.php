<?php

declare(strict_types=1);

namespace Tests\Feature\Retrieval;

use App\Ai\FakeLlm;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/**
 * H3-T3 plumbing: the demo corpus loads through the real workflow and the
 * demo set (20 answerable, 15 unanswerable) runs end to end through
 * retrieval:eval --answer. With fake providers the numbers mean nothing; the
 * real run needs OPENAI_API_KEY and ANTHROPIC_API_KEY (spike/retrieval-eval/README.md).
 */
final class EvalCorpusTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    public function test_demo_corpus_seeds_approved_indexed_documents_and_the_demo_set_runs_with_the_assistant(): void
    {
        Notification::fake();
        [$ws, $admin] = $this->makeWorkspace('staging', 'team');
        $approver = $this->addMember($ws, 'ap@example.test', 'approver');
        $corpus = base_path('../spike/retrieval-eval/demo/corpus.json');
        $set = base_path('../spike/retrieval-eval/demo/set.json');

        $this->artisan('retrieval:seed-corpus', ['corpus' => $corpus, '--workspace' => 'staging', '--author' => $admin->email, '--approver' => $approver->email])
            ->expectsOutputToContain('8 documents approved, 0 already present')->assertSuccessful();
        $this->artisan('retrieval:seed-corpus', ['corpus' => $corpus, '--workspace' => 'staging', '--author' => $admin->email, '--approver' => $approver->email])
            ->expectsOutputToContain('0 documents approved, 8 already present')->assertSuccessful();

        app(CurrentWorkspace::class)->runAs($ws->id, function (): void {
            $this->assertSame(8, Document::query()->live()->count());
            $this->assertSame(8, DocumentChunk::query()->whereNotNull('indexed_at')->distinct()->count('document_id'));
        });

        FakeLlm::$responses = ['[answer]' => (string) json_encode(['answer' => 'I cannot find this.', 'citations' => [], 'refused' => true])];
        $out = sys_get_temp_dir().'/demo-eval-'.uniqid();
        $this->artisan('retrieval:eval', ['set' => $set, '--workspace' => 'staging', '--as' => $admin->email, '--weights' => '1:0.6', '--answer' => true, '--out' => $out])->assertSuccessful();

        $md = (string) file_get_contents("{$out}/summary.md");
        $this->assertStringContainsString('| Answerable queries | 50 | 20 |', $md);
        $this->assertStringContainsString('| Unanswerable queries | 15 | 15 |', $md);
        $this->assertStringContainsString('| Assistant: correct refusal (--answer) | > 95% | 100% |', $md, 'the fake model always refuses');
    }

    public function test_seeding_refuses_to_run_in_production(): void
    {
        [$ws, $admin] = $this->makeWorkspace('prod', 'team');
        $this->app['env'] = 'production';
        $this->artisan('retrieval:seed-corpus', ['corpus' => base_path('../spike/retrieval-eval/demo/corpus.json'), '--workspace' => 'prod', '--author' => $admin->email])
            ->expectsOutputToContain('refusing to seed eval content in production')->assertFailed();
    }
}
