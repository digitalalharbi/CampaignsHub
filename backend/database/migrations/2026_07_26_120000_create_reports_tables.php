<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports domain: saved, generated, exportable documents built from the SAME metrics tables/APIs.
 * A report snapshots its data + section/chart config as JSONB so a completed report is reproducible
 * and exportable (PDF/XLSX/CSV) without recomputing. Heavy generation runs on a queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->string('name');
            $table->string('type'); // executive|project|campaign|platform|platform_comparison|weekly|monthly|custom
            $table->string('status')->default('draft'); // draft|processing|completed|failed
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->string('timezone')->default('Asia/Riyadh');
            $table->string('attribution_window')->nullable();
            $table->string('data_source')->default('daily_metrics');
            $table->jsonb('config')->nullable();   // sections, charts, filters, branding, notes
            $table->jsonb('data')->nullable();      // generated snapshot (kpis, series, tables)
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['tenant_id', 'project_id', 'status']);
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('report_id')->nullable();
            $table->string('name');
            $table->string('type');
            $table->string('frequency'); // weekly|monthly
            $table->string('day')->nullable(); // e.g. 'monday' or '1'
            $table->string('time')->default('08:00');
            $table->jsonb('config')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('report_id')->index();
            $table->string('format'); // pdf|xlsx|csv
            $table->string('status')->default('processing'); // processing|completed|failed
            $table->string('disk')->default('local');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('signed_token')->nullable()->index();
            $table->timestampTz('expires_at')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
        });

        Schema::create('report_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('report_id')->nullable();
            $table->uuid('schedule_id')->nullable();
            $table->string('email');
            $table->string('name')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_recipients');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('reports');
    }
};
