<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic J — account & notifications (S24), workspace settings (S23).
 * Notification preferences are per workspace (S24 rule), so they live on the
 * membership row. Workspace deletion is scheduled with a grace period, never
 * immediate (S23). Doc 04 v2.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $t): void {
            $t->json('notification_prefs')->nullable()->after('role');
        });
        Schema::table('workspaces', function (Blueprint $t): void {
            $t->timestamp('deletion_scheduled_at')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_members', fn (Blueprint $t) => $t->dropColumn('notification_prefs'));
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn('deletion_scheduled_at'));
    }
};
