<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Retrieval\QdrantStore;
use App\Retrieval\VectorStore;
use Illuminate\Console\Command;

/**
 * Creates the Qdrant collection (cosine, dims from services.openai.embedding_dims)
 * if it does not exist. Idempotent; run once per environment as part of the
 * pre-deploy job after migrations. No-op for the fake driver.
 */
final class VectorEnsureCollection extends Command
{
    protected $signature = 'vector:ensure-collection';

    protected $description = 'Create the vector store collection if it does not exist';

    public function handle(VectorStore $store): int
    {
        if (! $store instanceof QdrantStore) {
            $this->info('Vector driver is '.config('flowzapp.vector_driver').'; nothing to do.');

            return self::SUCCESS;
        }
        $store->ensureCollection();
        $this->info('Collection "'.config('services.qdrant.collection').'" is ready.');

        return self::SUCCESS;
    }
}
