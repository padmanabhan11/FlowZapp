<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documents / document_versions / document_steps — 04-Database-Schema
 * "Spaces, folders, documents", "Steps", "Versioning and approvals".
 *
 * documents.content is the working copy; the live approved content is the
 * document_versions row named by approved_version_id. That split is what lets
 * an approved document stay published while a new draft is edited (FR-407).
 *
 * body_text is a flattened plain-text mirror maintained by DocumentObserver
 * for MySQL FULLTEXT (no FULLTEXT over json). Never edited directly.
 *
 * source_recording_id's foreign key is added with the recordings table (Epic C).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $t->foreignUlid('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $t->string('title', 250);
            $t->enum('doc_type', ['sop', 'policy', 'handbook_page', 'note'])->default('sop');
            $t->enum('state', ['draft', 'in_review', 'approved', 'archived'])->default('draft');
            $t->foreignUlid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->ulid('source_recording_id')->nullable();
            $t->json('content');
            $t->mediumText('body_text')->nullable();
            $t->ulid('approved_version_id')->nullable();
            $t->boolean('requires_ack')->default(false);
            $t->timestamp('review_due_at')->nullable();
            $t->string('language', 10)->default('en');
            $t->foreignUlid('translation_of')->nullable()->constrained('documents')->nullOnDelete();
            $t->boolean('translation_stale')->default(false);
            $t->softDeletes();
            $t->timestamps();
            $t->index(['workspace_id', 'space_id', 'state'], 'ix_doc_scope');
            $t->index('folder_id', 'ix_doc_folder');
            $t->index('owner_id', 'ix_doc_owner');
            $t->index(['workspace_id', 'review_due_at'], 'ix_doc_review');
        });

        // FULLTEXT is MySQL-only; the test database (SQLite) skips it and search
        // falls back to LIKE in the query layer. Requires the stopword table and
        // innodb_ft_min_token_size=2 to be set on the cluster first (doc 04).
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('documents', function (Blueprint $t): void {
                $t->fullText(['title', 'body_text'], 'ft_doc_search');
            });
        }

        Schema::create('document_versions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $t->integer('version_number');
            $t->string('title', 250);
            $t->json('content');
            $t->mediumText('body_text')->nullable();
            $t->string('change_summary', 500)->nullable();
            $t->foreignUlid('authored_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'version_number'], 'uq_version_no');
            $t->index('workspace_id', 'ix_version_ws');
        });

        Schema::table('documents', function (Blueprint $t): void {
            $t->foreign('approved_version_id', 'fk_doc_approved_version')->references('id')->on('document_versions')->nullOnDelete();
        });

        Schema::create('document_steps', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            // '0' = the working draft. A real NULL would make the unique key useless
            // because MySQL treats each NULL as distinct (doc 04 "MySQL caveat").
            $t->char('version_id', 26)->default('0');
            $t->integer('position');
            $t->text('instruction');
            $t->text('note')->nullable();
            $t->text('expected_result')->nullable();
            $t->boolean('is_critical')->default(false);
            $t->boolean('is_checkpoint')->default(false);
            $t->ulid('media_asset_id')->nullable();
            $t->decimal('source_ts_start', 10, 3)->nullable();
            $t->decimal('source_ts_end', 10, 3)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'version_id', 'position'], 'uq_step_pos');
            $t->index(['workspace_id', 'document_id', 'version_id'], 'ix_step_doc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_steps');
        Schema::table('documents', function (Blueprint $t): void {
            $t->dropForeign('fk_doc_approved_version');
        });
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
