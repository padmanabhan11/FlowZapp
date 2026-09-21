<?php

declare(strict_types=1);

namespace App\Models;

/** One row per person per document per day (S20 "Most-read documents"). */
class DocumentRead extends TenantModel
{
    protected $fillable = ['document_id', 'user_id', 'read_on'];

    protected function casts(): array
    {
        return ['read_on' => 'date'];
    }
}
