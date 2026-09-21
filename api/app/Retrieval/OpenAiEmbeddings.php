<?php

declare(strict_types=1);

namespace App\Retrieval;

use Illuminate\Support\Facades\Http;

/** OpenAI text-embedding-3-small (decision 21 Sep 2026): 1536 dims, cheapest; zero retention per API terms. */
final class OpenAiEmbeddings implements Embeddings
{
    public function __construct(private readonly string $apiKey, private readonly string $model = 'text-embedding-3-small', private readonly int $dims = 1536) {}

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dims;
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($texts, 100) as $batch) {
            $res = Http::withToken($this->apiKey)->timeout(120)->retry(2, 1000)
                ->post('https://api.openai.com/v1/embeddings', ['model' => $this->model, 'input' => $batch, 'dimensions' => $this->dims])
                ->throw()->json();
            $rows = collect($res['data'] ?? [])->sortBy('index');
            foreach ($rows as $r) {
                $out[] = array_map('floatval', $r['embedding']);
            }
        }

        return $out;
    }
}
