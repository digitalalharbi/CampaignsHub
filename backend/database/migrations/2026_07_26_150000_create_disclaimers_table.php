<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central disclaimer / methodology overrides. System defaults live in config/disclaimers.php and are
 * always available; rows here override specific sections at a given scope. Resolution priority is
 * project → client → organization → system default (see DisclaimerResolver). Edits are versioned and
 * recorded in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disclaimers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            // organization = tenant-wide; client = a ClientWorkspace; project = a Project.
            $table->string('scope'); // organization|client|project
            $table->uuid('scope_id')->nullable()->index(); // null for organization scope
            $table->jsonb('payload');  // { sections: {...partial}, enabled: {...}, locale_default }
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('effective_at')->nullable(); // null = immediately effective
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disclaimers');
    }
};
