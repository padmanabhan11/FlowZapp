<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only (04-Database-Schema "Billing, usage, audit"): no updated_at, and
 * in production the application DB user is granted INSERT and SELECT only —
 * run once by a DBA:  REVOKE UPDATE, DELETE ON <db>.audit_log FROM 'app_user'@'%';
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $t): void {
            $t->id();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 80);          // 'member.role_changed', 'document.approved'
            $t->string('entity_type', 60);
            $t->ulid('entity_id')->nullable();
            $t->json('metadata')->nullable();
            $t->binary('ip', 16)->nullable();  // INET6_ATON()
            $t->timestamp('created_at')->nullable();
            $t->index(['workspace_id', 'created_at'], 'ix_audit_ws');
            $t->index(['entity_type', 'entity_id'], 'ix_audit_ent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
