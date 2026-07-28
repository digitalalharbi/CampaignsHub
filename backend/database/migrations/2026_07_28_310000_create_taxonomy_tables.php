<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central Taxonomy & Option engine storage. One place to define classification fields and their allowed
 * values, replacing per-page hardcoded arrays with a tenant-aware, permissioned, auditable set.
 *
 *   - taxonomy_definitions : a classification field (e.g. request.status, client.industry). tenant_id null =
 *                            platform (visible to all tenants); a non-null tenant_id is a tenant-private field.
 *                            is_system marks fields whose option keys are immutable (only labels/color/active
 *                            may change).
 *   - taxonomy_options     : an allowed value under a definition. Platform (tenant_id null) options are shared;
 *                            a tenant may add its own private options when the definition allows_custom_options.
 *                            parent_option_id supports hierarchical fields. A used option can never be
 *                            hard-deleted — merge / reassign / deactivate instead.
 *
 * This phase is additive: no existing status/enum column is changed. Adoption (wiring reads/writes to these
 * tables) is a later phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key');
            $table->string('module');
            $table->string('scope')->default('platform'); // platform|tenant|client|project|module
            $table->string('field_type')->default('single'); // single|multi|hierarchical
            $table->string('label_ar');
            $table->string('label_en');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('allows_custom_options')->default(true);
            $table->boolean('allows_multiple')->default(false);
            $table->unsignedInteger('maximum_selections')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->uuid('tenant_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'key']);
            $table->index(['module', 'is_active']);
            $table->index('key');
        });

        Schema::create('taxonomy_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key');
            $table->uuid('taxonomy_definition_id');
            $table->string('label_ar');
            $table->string('label_en');
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            $table->string('icon')->nullable();
            $table->uuid('parent_option_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->uuid('tenant_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('taxonomy_definition_id')->references('id')->on('taxonomy_definitions')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['taxonomy_definition_id', 'tenant_id', 'key']);
            $table->index(['taxonomy_definition_id', 'is_active', 'sort_order']);
            $table->index('parent_option_id');
        });

        // Self-referencing FK added after the table exists (Postgres needs the target PK in place first).
        Schema::table('taxonomy_options', function (Blueprint $table) {
            $table->foreign('parent_option_id')->references('id')->on('taxonomy_options')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_options');
        Schema::dropIfExists('taxonomy_definitions');
    }
};
