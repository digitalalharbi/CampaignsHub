<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WIDGET-REGISTRY-001 — which widgets a surface shows, in what order and how wide, for one project (or one shared link).
 *
 * `items` holds ONLY what someone chose: [{key, visible, order, size}]. A widget with no entry follows the registry's
 * default, so a widget added to the product later appears with its default instead of being silently absent from every
 * saved layout. One row per (workspace, scope, surface).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_layouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('scope_type', 16); // project | share_link
            $table->uuid('scope_id');
            $table->string('surface', 16);    // dashboard | analytics | report
            $table->jsonb('items');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'scope_type', 'scope_id', 'surface']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_layouts');
    }
};
