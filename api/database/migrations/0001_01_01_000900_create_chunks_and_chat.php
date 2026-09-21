<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** document_chunks (retrieval index mirror), chat_sessions, chat_messages — 04-Database-Schema "Retrieval index mirror", "Chat". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $t->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignUlid('version_id')->constrained('document_versions')->cascadeOnDelete();
            $t->ulid('folder_id')->nullable();
            $t->string('section_ref', 60);           // 'step:7', 'section:purpose', 'block:b3'
            $t->string('heading_path', 500)->nullable();
            $t->text('content');
            $t->integer('token_count')->nullable();
            $t->string('vector_id', 120)->nullable();  // id in the external index
            $t->string('embed_model', 60)->nullable();
            $t->timestamp('indexed_at')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'document_id', 'version_id'], 'ix_chunk_doc');
            $t->index(['workspace_id', 'space_id'], 'ix_chunk_scope');
            $t->index(['workspace_id', 'indexed_at'], 'ix_chunk_stale');
        });

        Schema::create('chat_sessions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('title', 250)->nullable();
            $t->foreignUlid('scope_document_id')->nullable()->constrained('documents')->nullOnDelete(); // S15 side panel scoped to a document
            $t->timestamps();
            $t->index(['workspace_id', 'user_id'], 'ix_chat_ws_user');
        });

        Schema::create('chat_messages', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $t->enum('role', ['user', 'assistant']);
            $t->mediumText('content');
            $t->json('citations')->nullable();        // [{document_id, version_id, section_ref, title, score}]
            $t->boolean('refused')->default(false);
            $t->integer('latency_ms')->nullable();
            $t->boolean('rated_helpful')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'session_id', 'created_at'], 'ix_msg_session');
            $t->index(['workspace_id', 'refused', 'created_at'], 'ix_msg_refused');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
        Schema::dropIfExists('document_chunks');
    }
};
