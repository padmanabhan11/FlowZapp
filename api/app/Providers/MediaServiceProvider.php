<?php

declare(strict_types=1);

namespace App\Providers;

use App\Media\FakeMediaStorage;
use App\Media\MediaStorage;
use App\Media\SpacesStorage;
use App\Ai\ClaudeDriver;
use App\Ai\FakeLlm;
use App\Ai\LlmDriver;
use App\Pipeline\DeepgramTranscriber;
use App\Pipeline\FakeTranscriber;
use App\Pipeline\NullTranscriber;
use App\Pipeline\WhisperTranscriber;
use App\Retrieval\Embeddings;
use App\Retrieval\FakeEmbeddings;
use App\Retrieval\FakeVectorStore;
use App\Retrieval\OpenAiEmbeddings;
use App\Retrieval\QdrantStore;
use App\Retrieval\VectorStore;
use App\Pipeline\Transcriber;
use Illuminate\Support\ServiceProvider;

final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaStorage::class, function (): MediaStorage {
            $driver = (string) config('flowzapp.media_driver', 'spaces');

            return $driver === 'fake' ? new FakeMediaStorage : new SpacesStorage;
        });

        $this->app->singleton(Transcriber::class, function (): Transcriber {
            return match ((string) config('flowzapp.transcription_driver', 'null')) {
                'whisper' => new WhisperTranscriber((string) config('services.openai.key')),
                'deepgram' => new DeepgramTranscriber((string) config('services.deepgram.key')),
                'fake' => new FakeTranscriber,
                default => new NullTranscriber,
            };
        });

        $this->app->singleton(Embeddings::class, function (): Embeddings {
            return match ((string) config('flowzapp.embeddings_driver', 'openai')) {
                'fake' => new FakeEmbeddings,
                default => new OpenAiEmbeddings((string) config('services.openai.key'), (string) config('services.openai.embedding_model', 'text-embedding-3-small'), (int) config('services.openai.embedding_dims', 1536)),
            };
        });

        $this->app->singleton(VectorStore::class, function (): VectorStore {
            return match ((string) config('flowzapp.vector_driver', 'qdrant')) {
                'fake' => new FakeVectorStore,
                default => new QdrantStore((string) config('services.qdrant.url'), config('services.qdrant.key'), (string) config('services.qdrant.collection', 'flowzapp'), (int) config('services.openai.embedding_dims', 1536)),
            };
        });

        $this->app->singleton(LlmDriver::class, function (): LlmDriver {
            return match ((string) config('flowzapp.llm_driver', 'claude')) {
                'fake' => new FakeLlm,
                default => new ClaudeDriver((string) config('services.anthropic.key'), (string) config('services.anthropic.model')),
            };
        });
    }
}
