<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic I — handbook and acknowledgement (FR-701..707, BR-23..26, BRL-07).
 *
 * acknowledgements is unique per (version, user): that is what makes a new
 * approved version re-trigger acknowledgement (FR-704, doc 04).
 * acknowledgement_targets is the assignment (FR-702) — who must acknowledge
 * a document, regardless of version. Added to doc 04 in v2.3 together with
 * documents.handbook_position (FR-701: order set by the space owner).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t): void {
            $t->unsignedInteger('handbook_position')->nullable()->after('requires_ack');
        });

        Schema::create('acknowledgement_targets', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['document_id', 'user_id'], 'uq_ack_target');
            $t->index(['workspace_id', 'user_id'], 'ix_ack_target_user');
        });

        Schema::create('acknowledgements', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignUlid('version_id')->constrained('document_versions')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('acknowledged_at');
            $t->timestamps();
            $t->unique(['version_id', 'user_id'], 'uq_ack');
            $t->index(['workspace_id', 'user_id'], 'ix_ack_ws_user');
            $t->index(['workspace_id', 'document_id'], 'ix_ack_ws_doc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acknowledgements');
        Schema::dropIfExists('acknowledgement_targets');
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('handbook_position'));
    }
};
