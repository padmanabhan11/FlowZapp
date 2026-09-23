<?php

declare(strict_types=1);

namespace App\Models;

/** One internal link block → its target document (B7). Maintained by DocumentObserver; never written directly. */
class DocumentLink extends TenantModel
{
    protected $fillable = ['source_document_id', 'target_document_id', 'block_id'];
}
