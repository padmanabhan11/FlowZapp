<?php

declare(strict_types=1);

namespace App\Observers;

use App\Documents\Content;
use App\Models\Document;

/**
 * Keeps documents.body_text in sync with content on every save (doc 04:
 * "the flattening belongs in one place: a model observer, not a controller").
 * Step text is appended by StepController after step writes (steps are rows).
 */
final class DocumentObserver
{
    public function saving(Document $document): void
    {
        if ($document->isDirty('content') || $document->isDirty('title') || $document->body_text === null) {
            $document->body_text = self::flatten($document);
        }
    }

    public static function flatten(Document $document): string
    {
        $text = $document->title."\n".Content::toText($document->content ?? []);
        $steps = $document->relationLoaded('steps') ? $document->steps : $document->steps()->get();
        foreach ($steps as $s) {
            $text .= "\n".$s->instruction.' '.($s->note ?? '').' '.($s->expected_result ?? '');
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
