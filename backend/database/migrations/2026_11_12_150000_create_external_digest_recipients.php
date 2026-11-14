<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — people OUTSIDE the platform (a client's project manager, say) who receive the
 * budget follow-up of the projects they are bound to, by email, on a cadence.
 *
 * Tenant-owned and deny-by-default: a recipient sees exactly the projects in its pivot rows, each row carrying the tenant so
 * a project of another workspace can never be bound (the controller checks it, and the foreign keys keep it honest). There is
 * no account and no login: the address, the cadence and the projects are all there is, and leaving is one signed click
 * through the existing `mail_unsubscribes` ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_digest_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('email', 254);
            $table->string('name', 120)->nullable();
            $table->string('cadence', 10)->default('weekly'); // daily | weekly | monthly
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('external_digest_recipient_projects', function (Blueprint $table): void {
            $table->uuid('recipient_id');
            $table->uuid('project_id');
            $table->uuid('tenant_id');

            $table->primary(['recipient_id', 'project_id']);
            $table->foreign('recipient_id')->references('id')->on('external_digest_recipients')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::create('external_digest_sends', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            $table->string('period_key', 24);
            $table->string('status', 32);
            $table->string('reason', 64)->nullable();
            $table->unsignedSmallInteger('projects')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('recipient_id')->references('id')->on('external_digest_recipients')->cascadeOnDelete();
            $table->unique(['recipient_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_digest_sends');
        Schema::dropIfExists('external_digest_recipient_projects');
        Schema::dropIfExists('external_digest_recipients');
    }
};
