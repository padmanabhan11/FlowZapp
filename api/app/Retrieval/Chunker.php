<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Documents\Content;
use App\Models\DocumentVersion;

/**
 * Semantic chunking (03 §6): one chunk per SOP step, one per section for
 * prose, one per block for free-form content. Each carries section_ref so a
 * citation can deep-link to the exact step.
 * @return list<array{section_ref: string, heading_path: ?string, content: string}>
 */
final class Chunker
{
    public static function chunks(DocumentVersion $v): array
    {
        $out = [];
        $title = $v->title;
        $c = $v->content ?? [];
        foreach (['purpose', 'scope', 'outcome'] as $k) {
            if (trim((string) ($c[$k] ?? '')) !== '') {
                $out[] = ['section_ref' => "section:$k", 'heading_path' => "$title › ".ucfirst($k), 'content' => "$title — ".ucfirst($k).": ".trim((string) $c[$k])];
            }
        }
        if (! empty($c['prerequisites'])) {
            $out[] = ['section_ref' => 'section:prerequisites', 'heading_path' => "$title › Prerequisites", 'content' => "$title — Prerequisites: ".implode('; ', array_map('strval', $c['prerequisites']))];
        }
        foreach ($v->steps()->get() as $s) {
            $text = "$title — Step {$s->position}: {$s->instruction}";
            if ($s->note) {
                $text .= " ({$s->note})";
            }
            if ($s->expected_result) {
                $text .= " Expected: {$s->expected_result}";
            }
            $out[] = ['section_ref' => "step:{$s->position}", 'heading_path' => "$title › Step {$s->position}", 'content' => $text];
        }
        $heading = null;
        foreach ($c['blocks'] ?? [] as $b) {
            if (! is_array($b)) {
                continue;
            }
            if (($b['type'] ?? '') === 'heading') {
                $heading = (string) ($b['text'] ?? '');
                continue;
            }
            $text = trim(Content::toText(['blocks' => [$b]]));
            if ($text === '') {
                continue;
            }
            $out[] = ['section_ref' => 'block:'.($b['id'] ?? ''), 'heading_path' => $title.($heading ? " › $heading" : ''), 'content' => ($heading ? "$title — $heading: " : "$title — ").$text];
        }

        return $out;
    }
}
