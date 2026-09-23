<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic B completion (doc 04 v2.6).
 *
 * document_templates — workspace-defined templates (B5-T3), saved from an
 * existing document; built-in templates stay in code (App\Documents\Templates).
 *
 * document_links — the internal-link graph (B7), rebuilt from a document's
 * link blocks whenever its content is saved, so backlinks are an indexed
 * lookup rather than a scan of every document's JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            $t->enum('doc_type', ['sop', 'policy', 'handbook_page', 'note'])->default('sop');
            $t->json('content');
            $t->json('steps');
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['workspace_id', 'name'], 'ix_tpl_ws_name');
        });

        Schema::create('document_links', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('source_document_id')->constrained('documents')->cascadeOnDelete();
            $t->ulid('target_document_id');   // no FK: a deleted target leaves the link to be reported as broken
            $t->string('block_id', 40);
            $t->timestamps();
            $t->unique(['source_document_id', 'block_id'], 'uq_doclink_block');
            $t->index(['workspace_id', 'target_document_id'], 'ix_doclink_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_links');
        Schema::dropIfExists('document_templates');
    }
};
