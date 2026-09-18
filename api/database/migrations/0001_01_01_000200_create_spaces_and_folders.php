<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            $t->boolean('is_handbook')->default(false);
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['workspace_id', 'name'], 'uq_space_name');
        });

        Schema::create('space_members', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->enum('role', ['admin', 'approver', 'editor', 'reader', 'guest'])->default('reader');
            $t->timestamps();
            $t->unique(['space_id', 'user_id'], 'uq_space_member');
            $t->index('user_id', 'ix_space_member_user');
            $t->index('workspace_id', 'ix_space_member_ws');
        });

        Schema::create('folders', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $t->foreignUlid('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $t->string('name', 120);
            $t->integer('position')->default(0);
            $t->unsignedTinyInteger('depth')->default(0);
            $t->timestamps();
            $t->index(['workspace_id', 'space_id', 'parent_id'], 'ix_folder_tree');
        });

        // MySQL 8.0.16+ enforces CHECK constraints. SQLite (used by the test
        // suite) cannot add one after CREATE, so depth is also validated in code.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement('ALTER TABLE folders ADD CONSTRAINT ck_folder_depth CHECK (depth <= 5)'); // allowlisted: DDL
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
        Schema::dropIfExists('space_members');
        Schema::dropIfExists('spaces');
    }
};
