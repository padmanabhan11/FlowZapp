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
        \App\Models\Folder::create(['space_id' => $space->id, 'name' => 'Clients']);
        \App\Models\WorkspaceInvite::create([
            'email' => 'bo@example.test', 'role' => 'reader',
            'token_hash' => str_repeat('0', 64), 'expires_at' => now()->addDay(),
        ]);

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
