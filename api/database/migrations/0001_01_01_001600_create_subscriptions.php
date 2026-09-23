<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K2: one subscription per workspace (doc 04 "subscriptions"). tier and seats
 * are the billed plan; workspaces.plan stays the effective plan the limits
 * read and is kept in sync. Provider columns are filled by the payment
 * provider driver once one is chosen (open decision, 03 §12); a scheduled
 * change is a downgrade waiting for the period end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->string('tier', 20)->default('free');
            $t->unsignedInteger('seats')->nullable();               // null = unlimited (Pro); Team defaults to 10
            $t->string('status', 40)->default('active');            // active | trialing | past_due | canceled
            $t->string('provider', 30)->nullable();                 // driver name that owns provider_* ids
            $t->string('provider_customer_id', 120)->nullable();
            $t->string('provider_sub_id', 120)->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->string('scheduled_tier', 20)->nullable();           // downgrade applied at scheduled_at
            $t->unsignedInteger('scheduled_seats')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamps();
            $t->unique('workspace_id', 'uq_sub_ws');
            $t->index(['provider', 'provider_sub_id'], 'ix_sub_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
