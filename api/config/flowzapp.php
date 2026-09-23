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

    // 'claude' (default) or 'fake' for tests.
    'llm_driver' => env('LLM_DRIVER', 'claude'),

    // Retrieval (decisions 21 Sep 2026): OpenAI text-embedding-3-small + Qdrant. 'fake' for tests.
    'embeddings_driver' => env('EMBEDDINGS_DRIVER', 'openai'),
    'vector_driver' => env('VECTOR_DRIVER', 'qdrant'),
    'retrieval' => [
        'top_k' => 20,
        'keep' => 8,
        'min_score' => (float) env('RETRIEVAL_MIN_SCORE', 0.25),   // below this the assistant refuses (FR-608)
    ],

    /**
     * Generation pipeline tuning (03 §5). Scene detection samples the video at
     * scene_fps and marks a change when ffmpeg's scene score exceeds
     * scene_threshold. Segment boundaries within snap_tolerance_sec of a screen
     * change move onto it. Frames whose 64-bit difference hashes differ by no
     * more than dedup_max_distance bits count as the same screen. Transcripts
     * longer than digest_over_chars are condensed per segment before the
     * generation call (D4-T2), in batches of about digest_batch_chars.
     */
    'pipeline' => [
        'scene_threshold' => (float) env('PIPELINE_SCENE_THRESHOLD', 0.3),
        'scene_fps' => 2,
        'snap_tolerance_sec' => 2.0,
        'dedup_max_distance' => 6,
        'digest_over_chars' => (int) env('PIPELINE_DIGEST_OVER_CHARS', 24000),
        'digest_batch_chars' => 8000,
    ],

    'workspace_defaults' => [
        'self_approval' => false,
        'review_cadence_months' => null,
        'retain_recordings' => true,
        'default_language' => 'en',
    ],

    /** S23: workspace deletion is scheduled, never immediate. */
    'workspace_deletion_grace_days' => (int) env('WORKSPACE_DELETION_GRACE_DAYS', 14),
];
