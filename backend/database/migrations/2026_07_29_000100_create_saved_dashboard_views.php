<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DASH-010-E: real persistence for saved dashboard views (filters + date range + comparison) per user/tenant.
 * Never localStorage. A partial-unique index guarantees at most ONE default view per (tenant, user, module).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_dashboard_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('module')->default('dashboard');
            $table->jsonb('filters')->nullable();
            $table->jsonb('date_range')->nullable();
            $table->jsonb('comparison')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'module']);
        });

        // Fail-closed: one default per (tenant, user, module).
        DB::statement('CREATE UNIQUE INDEX saved_dashboard_views_one_default ON saved_dashboard_views (tenant_id, user_id, module) WHERE is_default = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_dashboard_views');
    }
};
