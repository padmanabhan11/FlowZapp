<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3 (11-Screen-Functional-Specification) assigns spaces at invitation time.
 * Document 04 did not carry the column; added here — fold into doc 04 at the
 * next schema revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_invites', function (Blueprint $t): void {
            $t->json('space_ids')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invites', function (Blueprint $t): void {
            $t->dropColumn('space_ids');
        });
    }
};
