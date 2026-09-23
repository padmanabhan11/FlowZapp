<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** L4: model cost per answer, so the operator cost report covers chat as well as generation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $t): void {
            $t->decimal('cost_usd', 10, 5)->nullable()->after('latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $t): void {
            $t->dropColumn('cost_usd');
        });
    }
};
