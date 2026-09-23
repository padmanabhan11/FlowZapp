<?php

declare(strict_types=1);

namespace App\Retrieval;

interface Embeddings
{
    public function model(): string;

    public function dimensions(): int;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array;
}
