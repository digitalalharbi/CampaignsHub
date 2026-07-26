<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alert rule definitions. Evaluated against the SAME metrics aggregation the reports/dashboard use;
 * a triggered rule creates an app_notification (the notification center). Rules are tenant + project
 * scoped, respect a cooldown + quiet hours, and never raise a real alert from demo data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('unified_campaign_id')->nullable()->index();
            $table->uuid('connection_id')->nullable();
            $table->string('name');
            $table->string('rule_type');           // budget_exceeded|cpa_increased|roas_decreased|spend_no_conversions|...
            $table->string('metric')->nullable();  // spend|cpa|roas|conversions|...
            $table->string('operator')->nullable(); // gt|gte|lt|lte|eq
            $table->decimal('threshold', 18, 4)->nullable();
            $table->unsignedSmallInteger('evaluation_window')->default(7); // days
            $table->string('severity')->default('warning'); // info|warning|critical
            $table->unsignedInteger('cooldown')->default(86400); // seconds between re-alerts
            $table->jsonb('quiet_hours')->nullable(); // { enabled, start, end }
            $table->jsonb('channels')->nullable();    // { in_app: true, email: false }
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_triggered_at')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
