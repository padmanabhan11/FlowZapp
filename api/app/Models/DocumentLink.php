<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/** One internal link block → its target document (B7). Maintained by DocumentObserver; never written directly.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $source_document_id
 * @property string $target_document_id
 * @property string $block_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentLink extends TenantModel
{
    protected $fillable = ['source_document_id', 'target_document_id', 'block_id'];
}
