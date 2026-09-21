<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 04-Database-Schema "Recordings and the pipeline" — plus the deferred FK on documents.source_recording_id. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('space_id')->nullable()->constrained('spaces')->nullOnDelete();
            $t->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title', 250)->nullable();
            $t->string('storage_key', 500);                       // {workspace_id}/{recording_id}/source.webm
            $t->string('upload_id', 255)->nullable();             // S3 multipart upload id until completed
            $t->string('mime_type', 100)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->decimal('duration_sec', 10, 3)->nullable();
            $t->enum('state', ['pending_upload', 'uploaded', 'transcribing', 'segmenting', 'generating', 'draft_ready', 'failed'])->default('pending_upload');
            $t->string('failed_stage', 20)->nullable();
            $t->string('failure_reason', 500)->nullable();
            $t->foreignUlid('document_id')->nullable()->constrained('documents')->nullOnDelete(); // latest generated draft
            $t->timestamps();
            $t->index(['workspace_id', 'state'], 'ix_rec_scope');
        });

        Schema::table('documents', function (Blueprint $t): void {
            $t->foreign('source_recording_id', 'fk_doc_source_recording')->references('id')->on('recordings')->nullOnDelete();
        });

        Schema::create('transcripts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('recording_id')->constrained('recordings')->cascadeOnDelete();
            $t->string('language', 10)->nullable();
            $t->decimal('confidence', 5, 4)->nullable();
            $t->longText('full_text')->nullable();
            $t->json('words')->nullable();                         // [{w, start, end, conf}]
            $t->string('provider', 50)->nullable();               // which transcription driver produced this
            $t->timestamps();
            $t->unique('recording_id', 'uq_transcript_rec');
            $t->index('workspace_id', 'ix_transcript_ws');
        });

        Schema::create('recording_segments', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('recording_id')->constrained('recordings')->cascadeOnDelete();
            $t->integer('position');
            $t->decimal('ts_start', 10, 3);
            $t->decimal('ts_end', 10, 3);
            $t->text('summary')->nullable();
            $t->decimal('confidence', 5, 4)->nullable();
            $t->ulid('frame_asset_id')->nullable();
            $t->timestamps();
            $t->unique(['recording_id', 'position'], 'uq_seg_pos');
            $t->index('workspace_id', 'ix_seg_ws');
        });

        Schema::create('media_assets', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('recording_id')->nullable()->constrained('recordings')->cascadeOnDelete();
            $t->foreignUlid('document_id')->nullable()->constrained('documents')->cascadeOnDelete();
            $t->enum('kind', ['frame', 'image', 'attachment', 'video']);
            $t->string('storage_key', 500);
            $t->string('mime_type', 100)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'document_id'], 'ix_media_doc');
            $t->index('recording_id', 'ix_media_rec');
        });

        Schema::create('pipeline_jobs', function (Blueprint $t): void {
            $t->id();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('recording_id')->nullable()->constrained('recordings')->cascadeOnDelete();
            $t->foreignUlid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->enum('stage', ['transcribe', 'segment', 'frames', 'generate', 'embed']);
            $t->string('job_key', 191)->unique('uq_job_key');   // {recording_id}:{stage}:{input_hash}
            $t->enum('status', ['queued', 'running', 'succeeded', 'failed'])->default('queued');
            $t->integer('attempts')->default(0);
            $t->decimal('cost_usd', 10, 5)->nullable();
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'status', 'stage'], 'ix_job_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_jobs');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('recording_segments');
        Schema::dropIfExists('transcripts');
        Schema::table('documents', fn (Blueprint $t) => $t->dropForeign('fk_doc_source_recording'));
        Schema::dropIfExists('recordings');
    }
};
