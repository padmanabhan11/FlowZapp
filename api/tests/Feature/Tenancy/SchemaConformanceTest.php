<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The replacement for Postgres row-level security (03-Technical-Architecture §4.2,
 * 04-Database-Schema §7.3). For every table not on the global allowlist:
 *
 *   1. a workspace_id column exists and is NOT NULL;
 *   2. an index exists with workspace_id as its first column;
 *   3. an Eloquent model for the table exists and extends TenantModel;
 *   4. workspace_id is not in that model's $fillable.
 *
 * This test is not optional and must never be skipped to unblock a release.
 * Adding a table means either giving it workspace_id + a TenantModel, or
 * adding it to GLOBAL_TABLES with a justification in the PR.
 */
final class SchemaConformanceTest extends TestCase
{
    use RefreshDatabase;

    /** Tables that legitimately have no tenant. Every entry needs a reason in review. */
    private const GLOBAL_TABLES = [
        'workspaces',              // the tenant root itself
        'users',                   // a person belongs to many workspaces (FR-108)
        'migrations',
        'failed_jobs',
        'job_batches',
        'jobs',
        'cache',
        'cache_locks',
        'password_reset_tokens',
        'personal_access_tokens',  // Sanctum
        'sessions',
        'fulltext_stopwords',      // custom InnoDB stopword table (doc 04)
        'sqlite_sequence',         // test database only
    ];

    public function test_every_tenant_table_carries_workspace_id_and_a_tenant_model(): void
    {
        $models = $this->tenantModelsByTable();
        $problems = [];

        foreach (Schema::getTableListing() as $table) {
            $table = Str::afterLast($table, '.');
            if (in_array($table, self::GLOBAL_TABLES, true)) {
                continue;
            }

            $columns = collect(Schema::getColumns($table))->keyBy('name');
            if (! $columns->has('workspace_id')) {
                $problems[] = "$table: no workspace_id column";
                continue;
            }
            if ($columns['workspace_id']['nullable']) {
                $problems[] = "$table: workspace_id is nullable";
            }

            $leading = collect(Schema::getIndexes($table))
                ->contains(fn (array $ix) => ($ix['columns'][0] ?? null) === 'workspace_id');
            if (! $leading) {
                $problems[] = "$table: no index with workspace_id as its first column";
            }

            if (! isset($models[$table])) {
                $problems[] = "$table: no model extending TenantModel";
                continue;
            }

            /** @var Model $instance */
            $instance = new $models[$table];
            if (in_array('workspace_id', $instance->getFillable(), true)) {
                $problems[] = "$table: {$models[$table]} lists workspace_id in \$fillable";
            }
        }

        $this->assertSame([], $problems, "Tenancy conformance failures:\n - ".implode("\n - ", $problems));
    }

    public function test_no_model_with_workspace_id_bypasses_tenant_model(): void
    {
        $offenders = [];
        foreach ($this->allModelClasses() as $class) {
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || $ref->isSubclassOf(TenantModel::class)) {
                continue;
            }
            /** @var Model $m */
            $m = new $class;
            if (Schema::hasTable($m->getTable()) && Schema::hasColumn($m->getTable(), 'workspace_id')) {
                $offenders[] = "$class ({$m->getTable()}) has workspace_id but does not extend TenantModel";
            }
        }
        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /** @return array<string, class-string<Model>> table => model */
    private function tenantModelsByTable(): array
    {
        $out = [];
        foreach ($this->allModelClasses() as $class) {
            $ref = new ReflectionClass($class);
            if (! $ref->isAbstract() && $ref->isSubclassOf(TenantModel::class)) {
                /** @var Model $m */
                $m = new $class;
                $out[$m->getTable()] = $class;
            }
        }

        return $out;
    }

    /** @return list<class-string<Model>> */
    private function allModelClasses(): array
    {
        $classes = [];
        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            $relative = Str::of($file->getRelativePathname())->beforeLast('.php')->replace('/', '\\');
            $class = 'App\\Models\\'.$relative;
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
