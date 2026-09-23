<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documents\Content;
use App\Governance\Workflow;
use App\Models\Document;
use App\Models\DocumentStep;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

/**
 * Loads an eval corpus (spike/retrieval-eval/demo/corpus.json, or a
 * design partner's content in the same shape) into a staging workspace as
 * approved documents, through the real submit → approve workflow so they are
 * chunked and embedded exactly like production content. Then run
 * `retrieval:eval` against it (H3-T3 refusal eval, G2-T3, G3-T3).
 *
 * Idempotent per title: a document that already exists in the space is skipped.
 * Never run against a production workspace.
 */
final class SeedEvalCorpus extends Command
{
    protected $signature = 'retrieval:seed-corpus
        {corpus : path to corpus JSON}
        {--workspace= : workspace id or slug (staging only)}
        {--author= : email of the member who writes and submits}
        {--approver= : email of the member who approves (must differ unless the workspace allows self-approval)}';

    protected $description = 'Load an eval corpus into a staging workspace as approved, indexed documents';

    public function handle(CurrentWorkspace $current, Workflow $workflow): int
    {
        if (app()->environment('production')) {
            $this->error('refusing to seed eval content in production');

            return self::FAILURE;
        }
        $corpus = json_decode((string) @file_get_contents((string) $this->argument('corpus')), true);
        if (! is_array($corpus) || ! is_array($corpus['spaces'] ?? null)) {
            $this->error('the corpus must be JSON with a "spaces" array');

            return self::FAILURE;
        }
        $key = (string) $this->option('workspace');
        $ws = Workspace::query()->where('id', $key)->orWhere('slug', $key)->first();
        $author = User::query()->where('email', (string) $this->option('author'))->first();
        $approver = User::query()->where('email', (string) ($this->option('approver') ?: $this->option('author')))->first();
        if ($ws === null || $author === null || $approver === null) {
            $this->error('--workspace, --author and --approver must name an existing workspace and users');

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        $current->runAs($ws->id, function () use ($corpus, $author, $approver, $workflow, &$created, &$skipped): void {
            foreach ([$author, $approver] as $u) {
                if (! WorkspaceMember::query()->where('user_id', $u->id)->exists()) {
                    throw new \RuntimeException("{$u->email} is not a member of this workspace");
                }
            }
            foreach ($corpus['spaces'] as $sp) {
                $space = Space::query()->firstOrCreate(['name' => (string) $sp['name']], ['created_by' => $author->id]);
                foreach ([[$author, 'editor'], [$approver, 'approver']] as [$u, $role]) {
                    SpaceMember::query()->firstOrCreate(['space_id' => $space->id, 'user_id' => $u->id], ['role' => $role]);
                }
                foreach ($sp['documents'] ?? [] as $d) {
                    if (Document::query()->where('space_id', $space->id)->where('title', (string) $d['title'])->exists()) {
                        $skipped++;

                        continue;
                    }
                    $doc = Document::create([
                        'space_id' => $space->id, 'title' => (string) $d['title'], 'doc_type' => (string) ($d['doc_type'] ?? 'sop'),
                        'owner_id' => $author->id, 'created_by' => $author->id,
                        'content' => Content::merge(Content::empty(), ['purpose' => (string) ($d['purpose'] ?? ''), 'prerequisites' => array_map('strval', $d['prerequisites'] ?? [])]),
                    ]);
                    foreach (array_values($d['steps'] ?? []) as $i => $step) {
                        DocumentStep::create(['document_id' => $doc->id, 'position' => $i + 1, 'instruction' => (string) $step]);
                    }
                    $workflow->submit($doc->refresh(), $author);
                    $workflow->approve($doc->refresh(), $approver, 'Eval corpus');
                    $created++;
                }
            }
        });

        $this->info("{$created} documents approved, {$skipped} already present. Indexing runs on the `index` queue; check with retrieval:check-index --dry-run.");

        return self::SUCCESS;
    }
}
