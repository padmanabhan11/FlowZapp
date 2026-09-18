<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\CurrentWorkspace;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Base class for every table that carries workspace_id.
 *
 * Guarantees (03-Technical-Architecture §4.1):
 *  - reads are scoped to the current workspace (TenantScope);
 *  - workspace_id is set on create from CurrentWorkspace, never from input;
 *  - workspace_id is never mass-assignable and cannot be changed after create;
 *  - primary keys are 26-character ULIDs generated in PHP.
 *
 * tests/Feature/Tenancy/SchemaConformanceTest asserts that every tenant table
 * has a model extending this class. Do not extend Model directly for a table
 * that has a workspace_id column.
 */
abstract class TenantModel extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $guarded = ['id', 'workspace_id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            // A workspace_id supplied by the caller is ignored, never trusted.
            $model->setAttribute('workspace_id', app(CurrentWorkspace::class)->require());
        });

        static::updating(function (self $model): void {
            if ($model->isDirty('workspace_id')) {
                throw new RuntimeException(
                    static::class.': workspace_id is immutable. Rows never move between workspaces.'
                );
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
