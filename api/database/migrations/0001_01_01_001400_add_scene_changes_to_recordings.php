<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D2-T2: screen changes detected in the video (seconds), used to place segment boundaries and pick frames. Null = not analysed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->json('scene_changes')->nullable()->after('duration_sec');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->dropColumn('scene_changes');
        });
    }
};
