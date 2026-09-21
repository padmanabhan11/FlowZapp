<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Space;
use App\Models\TenantModel;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Backlog story A3, definition of done: with a valid context for workspace B,
 * every attempt to read or write workspace A's rows fails, for every tenant
 * table. Also pins the "no tenant → no rows" failure mode that replaces RLS.
 */
final class CrossTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Workspace::create(['name' => 'A', 'slug' => 'a', 'settings' => []]);
        $this->b = Workspace::create(['name' => 'B', 'slug' => 'b', 'settings' => []]);
    }

    private function current(): CurrentWorkspace
    {
        return app(CurrentWorkspace::class);
    }

    public function test_rows_created_in_a_are_invisible_from_b_and_from_no_tenant(): void
    {
        $this->current()->set($this->a->id);
        $space = Space::create(['name' => 'Ops']);
        $this->assertSame($this->a->id, $space->workspace_id);

        $this->current()->set($this->b->id);
        $this->assertNull(Space::find($space->id));
        $this->assertSame(0, Space::count());
        $this->assertNull(Space::query()->where('name', 'Ops')->first());

        $this->current()->set(null);
        $this->assertSame(0, Space::count(), 'no current workspace must yield no rows, never all rows');
    }

    public function test_workspace_id_from_input_is_ignored_on_create(): void
    {
        $this->current()->set($this->a->id);
        $space = new Space(['name' => 'Ops']);
        $space->forceFill(['workspace_id' => $this->b->id]);
        $space->save();

        $this->assertSame($this->a->id, Space::withoutGlobalScopes()->findOrFail($space->id)->workspace_id);
    }

    public function test_create_without_a_current_workspace_throws(): void
    {
        $this->current()->set(null);
        $this->expectException(RuntimeException::class);
        Space::create(['name' => 'Orphan']);
    }

    public function test_workspace_id_is_immutable(): void
    {
        $this->current()->set($this->a->id);
        $space = Space::create(['name' => 'Ops']);
        $this->expectException(RuntimeException::class);
        $space->forceFill(['workspace_id' => $this->b->id])->save();
    }

    public function test_update_and_delete_from_the_wrong_tenant_touch_nothing(): void
    {
        $this->current()->set($this->a->id);
        $space = Space::create(['name' => 'Ops']);

        $this->current()->set($this->b->id);
        $this->assertSame(0, Space::query()->whereKey($space->id)->update(['name' => 'Hijacked']));
        $this->assertSame(0, Space::query()->whereKey($space->id)->delete());

        $this->current()->set($this->a->id);
        $this->assertSame('Ops', Space::find($space->id)?->name);
    }

    public function test_every_tenant_model_is_scoped(): void
    {
        // Seed one row per tenant model in A using minimal attributes, then
        // prove B sees none. Models that need parents are seeded in order.
        $this->current()->set($this->a->id);
        $user = User::create(['name' => 'Ana', 'email' => 'ana@example.test']);
        WorkspaceMember::create(['user_id' => $user->id, 'role' => 'admin']);
        $space = Space::create(['name' => 'Ops']);
        \App\Models\SpaceMember::create(['space_id' => $space->id, 'user_id' => $user->id, 'role' => 'editor']);
        $folder = \App\Models\Folder::create(['space_id' => $space->id, 'name' => 'Clients']);
        \App\Models\FolderPermission::create(['folder_id' => $folder->id, 'user_id' => $user->id, 'role' => 'none']);
        \App\Models\WorkspaceInvite::create([
            'email' => 'bo@example.test', 'role' => 'reader',
            'token_hash' => str_repeat('0', 64), 'expires_at' => now()->addDay(),
        ]);
        \App\Audit\Audit::record('test.seeded', 'space', $space->id);
        $doc = \App\Models\Document::create(['space_id' => $space->id, 'title' => 'Doc', 'content' => \App\Documents\Content::empty(), 'created_by' => $user->id]);
        \App\Models\DocumentStep::create(['document_id' => $doc->id, 'position' => 1, 'instruction' => 'Do it']);
        \App\Models\DocumentVersion::create(['document_id' => $doc->id, 'version_number' => 1, 'title' => 'Doc', 'content' => $doc->content]);
        $ver = \App\Models\DocumentVersion::query()->where('document_id', $doc->id)->firstOrFail();
        \App\Models\DocumentChunk::create(['space_id' => $space->id, 'document_id' => $doc->id, 'version_id' => $ver->id, 'section_ref' => 'step:1', 'content' => 'Do it']);
        $cs = \App\Models\ChatSession::create(['user_id' => $user->id]);
        \App\Models\ChatMessage::create(['session_id' => $cs->id, 'role' => 'user', 'content' => 'hi']);
        \App\Models\DocumentRead::create(['document_id' => $doc->id, 'user_id' => $user->id, 'read_on' => now()->toDateString()]);
        \App\Models\AcknowledgementTarget::create(['document_id' => $doc->id, 'user_id' => $user->id, 'assigned_by' => $user->id]);
        \App\Models\Acknowledgement::create(['document_id' => $doc->id, 'version_id' => $ver->id, 'user_id' => $user->id, 'acknowledged_at' => now()]);
        \App\Models\Approval::create(['document_id' => $doc->id, 'requested_by' => $user->id, 'from_state' => 'draft', 'to_state' => 'in_review']);
        $rec = \App\Models\Recording::create(['space_id' => $space->id, 'uploaded_by' => $user->id, 'storage_key' => 'k/source.webm', 'state' => 'uploaded']);
        \App\Models\Transcript::create(['recording_id' => $rec->id, 'full_text' => 'hi', 'words' => []]);
        \App\Models\RecordingSegment::create(['recording_id' => $rec->id, 'position' => 1, 'ts_start' => 0, 'ts_end' => 1]);
        \App\Models\MediaAsset::create(['recording_id' => $rec->id, 'kind' => 'frame', 'storage_key' => 'k/f.jpg']);
        \App\Models\PipelineJob::create(['recording_id' => $rec->id, 'stage' => 'transcribe', 'job_key' => 'seed:transcribe:0']);

        $unseeded = [];
        $this->current()->set($this->b->id);
        foreach ($this->tenantModelClasses() as $class) {
            $countInB = $class::query()->count();
            $countTotal = $class::withoutGlobalScopes()->count();
            if ($countTotal === 0) {
                $unseeded[] = $class;
            }
            $this->assertSame(0, $countInB, "$class leaks rows across workspaces");
        }
        $this->assertSame([], $unseeded, 'Every tenant model must be seeded in this test so the leak check is real: '.implode(', ', $unseeded));
    }

    /** @return list<class-string<TenantModel>> */
    private function tenantModelClasses(): array
    {
        $out = [];
        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = 'App\\Models\\'.Str::of($file->getRelativePathname())->beforeLast('.php')->replace('/', '\\');
            if (class_exists($class) && is_subclass_of($class, TenantModel::class) && ! (new ReflectionClass($class))->isAbstract()) {
                /** @var Model $m */
                $m = new $class;
                if (Schema::hasTable($m->getTable())) {
                    $out[] = $class;
                }
            }
        }

        return $out;
    }
}
