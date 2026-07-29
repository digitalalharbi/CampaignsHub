<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable content for the PUBLIC surfaces (marketing homepage + the three external portals), so texts,
 * sections, buttons, ordering and enable/disable can be changed from System Settings WITHOUT touching code.
 *
 * One row per (tenant, page). `draft` is what the editor writes and previews; `published` is what the public
 * pages read. Publishing copies draft → published and stamps who/when, so a preview is never live by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_page_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            // home | portal_paid | portal_influencer | portal_tracking
            $table->string('page', 40);
            $table->jsonb('draft')->nullable();
            $table->jsonb('published')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_page_settings');
    }
};
