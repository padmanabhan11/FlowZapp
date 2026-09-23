<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $recording_id
 * @property string|null $language
 * @property float|null $confidence
 * @property string|null $full_text
 * @property array<mixed>|null $words
 * @property string|null $provider
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Transcript extends TenantModel
{
    protected $fillable = ['recording_id', 'language', 'confidence', 'full_text', 'words', 'provider'];

    protected function casts(): array
    {
        return ['words' => 'array', 'confidence' => 'float'];
    }
}
