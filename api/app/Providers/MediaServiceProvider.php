<?php

declare(strict_types=1);

namespace App\Providers;

use App\Media\FakeMediaStorage;
use App\Media\MediaStorage;
use App\Media\SpacesStorage;
use App\Pipeline\NullTranscriber;
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
                // 'whisper' => new WhisperTranscriber(...), 'deepgram' => new DeepgramTranscriber(...) — Epic D
                default => new NullTranscriber,
            };
        });
    }
}
