<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branding Center storage — logos/assets and non-file brand config, addressed across ownership scopes.
 *
 *   - branding_assets   : one stored brand file per (scope, scope_id, kind, theme). Bytes live on a PRIVATE
 *                         disk; path/original_path are internal and never exposed. The unique tuple lets an
 *                         upload upsert its slot rather than pile up variants. Tenant-scoped; uuid PK.
 *   - branding_settings : palette + type stack + white-label flag per (scope, scope_id). Tenant-scoped.
 *
 * scope_id is a bare uuid (client/project/report/…) — deliberately NOT a foreign key, because one column
 * references several different owner tables depending on scope. Effective-value resolution (client → tenant →
 * platform) is done in the service, not by a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('scope'); // platform|tenant|client|project|report|portal|email
            $table->uuid('scope_id')->nullable()->index(); // the concrete client/project/report when scoped
            $table->string('kind'); // primary_horizontal|report_logo|square_icon|favicon|email_header|client_logo
            $table->string('theme')->default('any'); // light|dark|any
            $table->string('disk');
            $table->string('path');
            $table->string('original_path')->nullable(); // pristine upload, preserved untouched
            $table->string('mime');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->unsignedBigInteger('bytes');
            $table->string('checksum');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'scope', 'scope_id', 'kind', 'theme'], 'branding_assets_slot_unique');
            $table->index(['tenant_id', 'scope', 'scope_id']);
        });

        Schema::create('branding_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('scope'); // platform|tenant|client|project|report|portal|email
            $table->uuid('scope_id')->nullable()->index();
            $table->jsonb('colors')->nullable();
            $table->jsonb('fonts')->nullable();
            $table->boolean('white_label')->default(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'scope', 'scope_id'], 'branding_settings_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_settings');
        Schema::dropIfExists('branding_assets');
    }
};
