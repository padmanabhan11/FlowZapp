<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_members', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->enum('role', ['admin', 'approver', 'editor', 'reader', 'guest'])->default('editor');
            $t->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('joined_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'user_id'], 'uq_ws_member');
            $t->index('user_id', 'ix_ws_member_user');
        });

        Schema::create('workspace_invites', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->string('email', 190);
            $t->enum('role', ['admin', 'approver', 'editor', 'reader', 'guest'])->default('editor');
            $t->char('token_hash', 64)->unique('uq_invite_token');
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['workspace_id', 'email'], 'ix_invite_ws_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invites');
        Schema::dropIfExists('workspace_members');
    }
};
