<?php

declare(strict_types=1);

namespace App\Observers;

use App\Documents\Content;
use App\Models\Document;
use App\Models\DocumentLink;

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

    /** Rebuild the internal-link graph from link blocks whenever content changes (B7). */
    public function saved(Document $document): void
    {
        if ($document->wasRecentlyCreated || $document->wasChanged('content')) {
            self::syncLinks($document);
        }
    }

    public static function syncLinks(Document $document): void
    {
        $want = [];
        foreach (($document->content['blocks'] ?? []) as $b) {
            if (($b['type'] ?? null) === 'link' && ! empty($b['document_id']) && ! empty($b['id']) && $b['document_id'] !== $document->id) {
                $want[(string) $b['id']] = (string) $b['document_id'];
            }
        }
        $have = DocumentLink::query()->where('source_document_id', $document->id)->get()->keyBy('block_id');
        foreach ($have as $blockId => $link) {
            if (! isset($want[$blockId])) {
                $link->delete();
            } elseif ($link->target_document_id !== $want[$blockId]) {
                $link->forceFill(['target_document_id' => $want[$blockId]])->save();
            }
        }
        foreach ($want as $blockId => $target) {
            if (! $have->has($blockId)) {
                DocumentLink::create(['source_document_id' => $document->id, 'target_document_id' => $target, 'block_id' => $blockId]);
            }
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
