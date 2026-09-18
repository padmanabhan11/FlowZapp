<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope applied by every TenantModel.
 *
 * With a current workspace: every query is constrained to it.
 * Without one: the query is forced to match nothing. Under Postgres RLS a
 * missing filter returned nothing; under MySQL a missing filter would return
 * every tenant's rows. This scope restores the safe failure mode.
 *
 * There is deliberately no "withoutTenancy" convenience. Code that must cross
 * workspaces (reporting jobs, migrations) removes the scope by name (see check-raw-queries.php)
 * explicitly, and every such call site is reviewed. scripts/check-raw-queries.php
 * lists them.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var CurrentWorkspace $current */
        $current = app(CurrentWorkspace::class);
        $column = $model->qualifyColumn('workspace_id');

        if ($current->isSet()) {
            $builder->where($column, $current->id());
        } else {
            $builder->whereRaw('1 = 0'); // allowlisted: the "no tenant → no rows" guard
        }
    }
}
