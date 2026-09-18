<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global tables (04-Database-Schema, "Core tenancy"). These are the only
 * application tables without workspace_id; they are on the conformance-test
 * allowlist. Every later migration creates tenant-scoped tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name', 120);
            $t->string('slug', 80)->unique('uq_workspaces_slug');
            $t->enum('plan', ['free', 'pro', 'team'])->default('free');
            $t->json('settings');
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name', 120);
            $t->string('email', 190)->unique('uq_users_email');
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password')->nullable()->comment('null when magic-link only');
            $t->string('locale', 10)->default('en');
            $t->string('avatar_path')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $t): void {
            $t->string('email', 190)->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->foreignUlid('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('workspaces');
    }
};
