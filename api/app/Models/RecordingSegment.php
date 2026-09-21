<?php

declare(strict_types=1);

namespace App\Models;

class RecordingSegment extends TenantModel
{
    protected $fillable = ['recording_id', 'position', 'ts_start', 'ts_end', 'summary', 'confidence', 'frame_asset_id'];

    protected function casts(): array
    {
        return ['ts_start' => 'float', 'ts_end' => 'float', 'confidence' => 'float'];
    }
}
