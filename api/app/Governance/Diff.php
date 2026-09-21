<?php

declare(strict_types=1);

namespace App\Governance;

/**
 * Block-level diff between two document snapshots (F5): added, removed,
 * modified, moved — each labelled. Moved blocks are reported as moved, not as
 * a delete plus an add (S13). Steps are matched by id (rows survive approval
 * copies via the original id kept in the snapshot), blocks by id, sections by
 * key.
 *
 * A snapshot is: ['title', 'content' => Content, 'steps' => [[id, position, instruction, note, expected_result, is_critical, is_checkpoint], …]]
 */
final class Diff
{
    /** @param array<string,mixed> $from @param array<string,mixed> $to @return list<array<string,mixed>> */
    public static function compute(array $from, array $to): array
    {
        $out = [];
        if (($from['title'] ?? '') !== ($to['title'] ?? '')) {
            $out[] = ['kind' => 'modified', 'type' => 'title', 'ref' => 'title', 'from' => $from['title'] ?? '', 'to' => $to['title'] ?? ''];
        }
        foreach (['purpose', 'scope', 'outcome'] as $k) {
            $a = (string) ($from['content'][$k] ?? '');
            $b = (string) ($to['content'][$k] ?? '');
            if ($a !== $b) {
                $out[] = ['kind' => $a === '' ? 'added' : ($b === '' ? 'removed' : 'modified'), 'type' => 'section', 'ref' => "section:$k", 'from' => $a, 'to' => $b];
            }
        }
        $pa = array_values($from['content']['prerequisites'] ?? []);
        $pb = array_values($to['content']['prerequisites'] ?? []);
        if ($pa !== $pb) {
            $out[] = ['kind' => 'modified', 'type' => 'section', 'ref' => 'section:prerequisites', 'from' => $pa, 'to' => $pb];
        }

        $out = array_merge($out, self::diffList(
            $from['steps'] ?? [], $to['steps'] ?? [], 'step',
            fn ($s) => (string) ($s['id'] ?? ''),
            fn ($s) => [$s['instruction'] ?? '', $s['note'] ?? null, $s['expected_result'] ?? null, (bool) ($s['is_critical'] ?? false), (bool) ($s['is_checkpoint'] ?? false)],
        ));
        $out = array_merge($out, self::diffList(
            $from['content']['blocks'] ?? [], $to['content']['blocks'] ?? [], 'block',
            fn ($b) => (string) ($b['id'] ?? ''),
            fn ($b) => array_diff_key($b, ['id' => 1]),
        ));

        return $out;
    }

    /** @param list<array<string,mixed>> $a @param list<array<string,mixed>> $b @return list<array<string,mixed>> */
    private static function diffList(array $a, array $b, string $type, callable $id, callable $fingerprint): array
    {
        $aById = [];
        foreach (array_values($a) as $i => $x) {
            $aById[$id($x)] = ['i' => $i, 'v' => $x];
        }
        $bById = [];
        foreach (array_values($b) as $i => $x) {
            $bById[$id($x)] = ['i' => $i, 'v' => $x];
        }
        $out = [];
        foreach ($aById as $k => $row) {
            if (! isset($bById[$k])) {
                $out[] = ['kind' => 'removed', 'type' => $type, 'ref' => "$type:".($row['i'] + 1), 'id' => $k, 'from' => $row['v'], 'to' => null];
            }
        }
        // Relative order among surviving items decides "moved": compare the sequence of survivors.
        $survivorsA = array_values(array_filter(array_keys($aById), fn ($k) => isset($bById[$k])));
        $survivorsB = array_values(array_filter(array_keys($bById), fn ($k) => isset($aById[$k])));
        $moved = [];
        if ($survivorsA !== $survivorsB) {
            $lis = self::longestCommonSubsequence($survivorsA, $survivorsB);
            $moved = array_flip(array_diff($survivorsB, $lis));
        }
        foreach ($bById as $k => $row) {
            $ref = "$type:".($row['i'] + 1);
            if (! isset($aById[$k])) {
                $out[] = ['kind' => 'added', 'type' => $type, 'ref' => $ref, 'id' => $k, 'from' => null, 'to' => $row['v']];
                continue;
            }
            $changed = $fingerprint($aById[$k]['v']) != $fingerprint($row['v']);
            if (isset($moved[$k])) {
                $out[] = ['kind' => 'moved', 'type' => $type, 'ref' => $ref, 'id' => $k, 'from_position' => $aById[$k]['i'] + 1, 'to_position' => $row['i'] + 1, 'from' => $aById[$k]['v'], 'to' => $row['v'], 'also_modified' => $changed];
            } elseif ($changed) {
                $out[] = ['kind' => 'modified', 'type' => $type, 'ref' => $ref, 'id' => $k, 'from' => $aById[$k]['v'], 'to' => $row['v']];
            }
        }

        return $out;
    }

    /** @param list<string> $a @param list<string> $b @return list<string> */
    private static function longestCommonSubsequence(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $L[$i][$j] = $a[$i] === $b[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = $a[$i];
                $i++;
                $j++;
            } elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $out;
    }
}
