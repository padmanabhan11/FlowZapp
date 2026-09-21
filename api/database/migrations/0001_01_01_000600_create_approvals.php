<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** approvals — every state transition with who, from, to, comment (04 "Versioning and approvals"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->enum('from_state', ['draft', 'in_review', 'approved', 'archived']);
            $t->enum('to_state', ['draft', 'in_review', 'approved', 'archived']);
            $t->text('comment')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'document_id', 'created_at'], 'ix_appr_doc');
        });
        Schema::table('documents', function (Blueprint $t): void {
            $t->foreignUlid('submitted_by')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable()->after('submitted_by');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('submitted_by');
            $t->dropColumn('submitted_at');
        });
        Schema::dropIfExists('approvals');
    }
};
