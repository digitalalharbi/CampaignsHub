<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §14.5 — a report carries its own scope, and a scope can be saved and reused.
 *
 * Two columns rather than one table, because they answer different questions. `reports.scope` is
 * what THIS report is about, and it must be editable in place: narrowing a report to two platforms
 * should not force the operator to create a second report and explain to the client why the first
 * link stopped matching. `report_scope_templates` is a scope somebody expects to use again — «the
 * monthly sales-path view» — which is a reusable object with a name, not a property of any one report.
 *
 * Both are `jsonb`: the scope has twelve axes, several of which are lists, and a column per axis
 * would be eleven joins to answer «what is this report about?». `ReportScope` is the only thing that
 * reads or writes the shape, so the schema does not need to know it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->jsonb('scope')->nullable()->after('config');
        });

        Schema::create('report_scope_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            /*
             * Project-scoped by default, tenant-wide when null.
             *
             * A template naming campaign ids is meaningless outside its project, but one naming only
             * platforms, objectives or a marketing path is exactly the thing an agency wants to reuse
             * across every client. Nullable rather than two tables, and the controller refuses to
             * save a project-less template that names project-specific ids.
             */
            $table->uuid('project_id')->nullable()->index();

            $table->string('name', 120);
            $table->string('description', 400)->nullable();
            $table->jsonb('scope');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            // One name per project, so «الملخص الشهري» cannot become three different scopes nobody
            // can tell apart in a picker.
            $table->unique(['tenant_id', 'project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_scope_templates');

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }
};
