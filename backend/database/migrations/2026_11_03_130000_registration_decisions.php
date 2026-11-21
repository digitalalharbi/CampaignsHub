<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIGNUP-DECISION-001 — a reviewer's decision on one application, as a record of its own.
 *
 * Additive and writes no row: no application is decided, activated or repaired by deploying this, and no payment,
 * verification or subscription fact changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('registration_request_id')->index();
            $table->string('outcome', 48);
            $table->string('status', 24);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->json('terms')->nullable();
            $table->json('access')->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('registration_request_id')->references('id')->on('registration_requests')->cascadeOnDelete();
        });

        // The database's own answer to two reviewers deciding at once: one decision in force per application.
        DB::statement("create unique index registration_decisions_one_in_force on registration_decisions (registration_request_id) where status = 'in_force'");

        Schema::table('subscriptions', function (Blueprint $table): void {
            // Why this subscription exists when no provider settled it. Null means an ordinary paid subscription.
            $table->string('commercial_basis', 32)->nullable();
            $table->uuid('registration_decision_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['commercial_basis', 'registration_decision_id']);
        });

        Schema::dropIfExists('registration_decisions');
    }
};
