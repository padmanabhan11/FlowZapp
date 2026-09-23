<?php

declare(strict_types=1);

namespace App\Governance;

use App\Models\Document;
use App\Models\DocumentStep;
use App\Models\DocumentVersion;

/** Builds the comparable snapshot shape used by Diff for the working copy or a version. */
final class Snapshot
{
    /**
     * @return array<string,mixed>
     */
    public static function ofWorkingCopy(Document $doc): array
    {
        return ['title' => $doc->title, 'content' => $doc->content ?? [], 'steps' => $doc->steps()->get()->map(fn (DocumentStep $s) => self::step($s, $s->id))->all()];
    }

    /**
     * @return array<string,mixed>
     */
    public static function ofVersion(DocumentVersion $v): array
    {
        // Version steps keep the working-copy step id in `origin_step_id` (content JSON) so diffs match across approvals.
        return ['title' => $v->title, 'content' => $v->content ?? [], 'steps' => array_values($v->content['_steps'] ?? [])];
    }

    /**
     * @return array<string,mixed>
     */
    public static function step(DocumentStep $s, string $id): array
    {
        return [
            'id' => $id, 'position' => $s->position, 'instruction' => $s->instruction, 'note' => $s->note, 'expected_result' => $s->expected_result,
            'is_critical' => (bool) $s->is_critical, 'is_checkpoint' => (bool) $s->is_checkpoint, 'media_asset_id' => $s->media_asset_id,
            'source_ts_start' => $s->source_ts_start, 'source_ts_end' => $s->source_ts_end,
        ];
    }
}
