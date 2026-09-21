<?php

declare(strict_types=1);

namespace App\Models;

class Transcript extends TenantModel
{
    protected $fillable = ['recording_id', 'language', 'confidence', 'full_text', 'words', 'provider'];

    protected function casts(): array
    {
        return ['words' => 'array', 'confidence' => 'float'];
    }
}
