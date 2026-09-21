<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folder-level overrides (F10: "Permissions are granted at Space level and may
 * be overridden at folder level. Overrides are additive-or-restrictive and
 * always explicit"). Not in doc 04 v2.1 — added 21 Sep 2026 (v2.2 note).
 * role 'none' removes access to the folder subtree; any other role replaces
 * the inherited space role for that subtree. Nearest ancestor override wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_permissions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('folder_id')->constrained('folders')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->enum('role', ['none', 'approver', 'editor', 'reader', 'guest']);
            $t->foreignUlid('set_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['folder_id', 'user_id'], 'uq_folder_perm');
            $t->index(['workspace_id', 'user_id'], 'ix_folder_perm_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_permissions');
    }
};
