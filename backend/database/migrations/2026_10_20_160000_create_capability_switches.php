<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAP-REGISTRY-001 — a module switched on or off for a part of one workspace.
 *
 * One row is one decision at one level (the whole agency, one client, one project). No row means
 * «inherit», which is why a row is never written for the default. `scope_key` is the scope id, or the
 * tenant id for the tenant level, so the uniqueness below holds even where `scope_id` is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_switches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('scope_type', 16); // tenant | client | project
            $table->uuid('scope_id')->nullable();
            $table->uuid('scope_key');
            $table->string('module', 48);
            $table->boolean('enabled');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'scope_type', 'scope_key', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_switches');
    }
};
