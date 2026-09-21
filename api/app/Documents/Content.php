<?php

declare(strict_types=1);

namespace App\Documents;

use Illuminate\Validation\Rule;

/**
 * The structured-JSON document model (F2: "Content is stored as structured
 * JSON, not HTML"). One shape for every doc_type; SOP sections are fixed slots
 * and the ordered steps live in document_steps (F1: steps are rows as well as
 * content). Free-form blocks carry handbook pages, policies and notes.
 *
 * {
 *   "version": 1,
 *   "purpose": "…", "scope": "…", "prerequisites": ["…"], "outcome": "…",
 *   "blocks": [ { "id": "b1", "type": "paragraph", "text": "…" }, … ]
 * }
 */
final class Content
{
    public const SCHEMA_VERSION = 1;

    public const BLOCK_TYPES = [
        'paragraph', 'heading', 'bullet_list', 'numbered_list', 'checklist', 'table',
        'code', 'callout', 'image', 'video', 'file', 'divider', 'link',
    ];

    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return ['version' => self::SCHEMA_VERSION, 'purpose' => '', 'scope' => '', 'prerequisites' => [], 'outcome' => '', 'blocks' => []];
    }

    /** Laravel validation rules for a `content` payload, prefixed with $key. @return array<string, mixed> */
    public static function rules(string $key = 'content'): array
    {
        return [
            $key => ['array'],
            "$key.version" => ['sometimes', 'integer', Rule::in([self::SCHEMA_VERSION])],
            "$key.purpose" => ['sometimes', 'nullable', 'string', 'max:5000'],
            "$key.scope" => ['sometimes', 'nullable', 'string', 'max:5000'],
            "$key.outcome" => ['sometimes', 'nullable', 'string', 'max:5000'],
            "$key.prerequisites" => ['sometimes', 'array', 'max:50'],
            "$key.prerequisites.*" => ['string', 'max:500'],
            "$key.blocks" => ['sometimes', 'array', 'max:2000'],
            "$key.blocks.*.id" => ['required', 'string', 'max:40'],
            "$key.blocks.*.type" => ['required', Rule::in(self::BLOCK_TYPES)],
            "$key.blocks.*.text" => ['sometimes', 'nullable', 'string', 'max:20000'],
            "$key.blocks.*.level" => ['sometimes', 'integer', 'between:1,3'],
            "$key.blocks.*.items" => ['sometimes', 'array', 'max:500'],
            "$key.blocks.*.variant" => ['sometimes', Rule::in(['info', 'warning', 'danger'])],
            "$key.blocks.*.asset_id" => ['sometimes', 'nullable', 'string', 'size:26'],  // images/files reference storage by ID, never base64
            "$key.blocks.*.document_id" => ['sometimes', 'nullable', 'string', 'size:26'],
            "$key.blocks.*.rows" => ['sometimes', 'array', 'max:500'],
            "$key.blocks.*.language" => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /** Merge a partial payload over existing content, keeping the schema whole. @param array<string,mixed> $current @param array<string,mixed> $patch @return array<string,mixed> */
    public static function merge(array $current, array $patch): array
    {
        $out = array_replace(self::empty(), $current);
        foreach (['purpose', 'scope', 'outcome', 'prerequisites', 'blocks'] as $k) {
            if (array_key_exists($k, $patch)) {
                $out[$k] = $patch[$k] ?? ($k === 'prerequisites' || $k === 'blocks' ? [] : '');
            }
        }
        $out['version'] = self::SCHEMA_VERSION;

        return $out;
    }

    /** Flatten to plain text for FULLTEXT / LIKE search (title is added by the observer). @param array<string,mixed> $content */
    public static function toText(array $content): string
    {
        $parts = [];
        foreach (['purpose', 'scope', 'outcome'] as $k) {
            if (! empty($content[$k])) {
                $parts[] = (string) $content[$k];
            }
        }
        foreach ($content['prerequisites'] ?? [] as $p) {
            $parts[] = (string) $p;
        }
        foreach ($content['blocks'] ?? [] as $b) {
            $parts[] = self::blockText(is_array($b) ? $b : []);
        }

        return trim(preg_replace('/\s+/u', ' ', implode("\n", array_filter($parts))) ?? '');
    }

    /** @param array<string,mixed> $b */
    private static function blockText(array $b): string
    {
        $t = [];
        if (isset($b['text'])) {
            $t[] = (string) $b['text'];
        }
        foreach ($b['items'] ?? [] as $item) {
            $t[] = is_array($item) ? (string) ($item['text'] ?? '') : (string) $item;
        }
        foreach ($b['rows'] ?? [] as $row) {
            foreach (is_array($row) ? $row : [] as $cell) {
                $t[] = is_array($cell) ? (string) ($cell['text'] ?? '') : (string) $cell;
            }
        }

        return implode(' ', array_filter($t));
    }
}
