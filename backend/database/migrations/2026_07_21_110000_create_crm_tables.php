<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM core schema. Every table is tenant-scoped (tenant_id) with important uniqueness scoped to the
 * tenant. Money is NUMERIC, timestamps are TIMESTAMPTZ, flexible fields are JSONB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->string('city')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('tags')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->index(['tenant_id', 'email']);
        });

        Schema::create('pipelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('pipeline_id');
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedSmallInteger('probability')->default(0); // 0..100
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('pipeline_id')->references('id')->on('pipelines')->cascadeOnDelete();
            $table->index(['pipeline_id', 'sort']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('source')->default('manual');      // website|referral|paid|whatsapp|...
            $table->string('status')->default('new');          // new|contacted|qualified|...|won|lost
            $table->decimal('estimated_value', 14, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->text('notes')->nullable();
            $table->jsonb('tags')->nullable();
            $table->string('lost_reason')->nullable();
            $table->uuid('converted_opportunity_id')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'source']);
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('pipeline_id');
            $table->uuid('stage_id');
            $table->uuid('company_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->unsignedSmallInteger('probability')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->string('status')->default('open'); // open|won|lost
            $table->string('lost_reason')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('pipeline_id')->references('id')->on('pipelines')->cascadeOnDelete();
            $table->foreign('stage_id')->references('id')->on('pipeline_stages')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        // Unified activity timeline (polymorphic: attaches to leads, opportunities, companies…).
        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('note'); // note|call|meeting|email|status_change
            $table->text('body')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['tenant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('pipelines');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('companies');
    }
};
