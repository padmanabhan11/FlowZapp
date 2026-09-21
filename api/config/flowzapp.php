<?php

declare(strict_types=1);

return [
    // Where the Angular app lives; magic links and invite links land here.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:4200'),

    'invite_ttl_days' => 7,

    // 'spaces' in staging/production; 'fake' for tests and local dev without credentials.
    'media_driver' => env('MEDIA_DRIVER', 'spaces'),

    // Speech-to-text driver: 'null' until the D0 evaluation picks one ('whisper' | 'deepgram').
    'transcription_driver' => env('TRANSCRIPTION_DRIVER', 'null'),

    'workspace_defaults' => [
        'self_approval' => false,
        'review_cadence_months' => null,
        'retain_recordings' => true,
        'default_language' => 'en',
    ],
];
