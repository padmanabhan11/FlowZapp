<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $recording_id
 * @property int $position
 * @property float $ts_start
 * @property float $ts_end
 * @property string|null $summary
 * @property float|null $confidence
 * @property string|null $frame_asset_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecordingSegment extends TenantModel
{
    protected $fillable = ['recording_id', 'position', 'ts_start', 'ts_end', 'summary', 'confidence', 'frame_asset_id'];

    protected function casts(): array
    {
        return ['ts_start' => 'float', 'ts_end' => 'float', 'confidence' => 'float'];
    }
}
