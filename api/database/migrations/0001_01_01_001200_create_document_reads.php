<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic L — "Most-read documents" (S20). One row per person per document per
 * day, written when the published view is served. Kept out of audit_log,
 * which is for auditors, not analytics. Doc 04 v2.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reads', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->date('read_on');
            $t->timestamps();
            $t->unique(['document_id', 'user_id', 'read_on'], 'uq_doc_read_day');
            $t->index(['workspace_id', 'read_on'], 'ix_doc_read_ws_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reads');
    }
};
