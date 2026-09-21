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

        $this->app->singleton(LlmDriver::class, function (): LlmDriver {
            return match ((string) config('flowzapp.llm_driver', 'claude')) {
                'fake' => new FakeLlm,
                default => new ClaudeDriver((string) config('services.anthropic.key'), (string) config('services.anthropic.model')),
            };
        });
    }
}
