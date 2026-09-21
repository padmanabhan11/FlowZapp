<?php

declare(strict_types=1);

namespace App\Retrieval;

/** Deterministic bag-of-words hashing embedding for tests: similar text → similar vector. */
final class FakeEmbeddings implements Embeddings
{
    public const DIMS = 64;

    public function model(): string
    {
        return 'fake-hash-64';
    }

    public function dimensions(): int
    {
        return self::DIMS;
    }

    public function embed(array $texts): array
    {
        return array_map(function (string $t): array {
            $v = array_fill(0, self::DIMS, 0.0);
            foreach (preg_split('/\W+/u', mb_strtolower($t)) ?: [] as $w) {
                if ($w === '') {
                    continue;
                }
                $w = mb_substr($w, 0, 5); // crude stemming so "refund"/"refunds" land together
                $h = crc32($w);
                $v[$h % self::DIMS] += 1.0;
                $v[($h >> 8) % self::DIMS] += 0.5;
            }
            $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v))) ?: 1.0;

            return array_map(fn ($x) => $x / $norm, $v);
        }, $texts);
    }
}
