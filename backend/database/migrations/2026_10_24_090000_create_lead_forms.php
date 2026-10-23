<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FORMS-HUB-001 — a project's own lead forms, versioned, and what was submitted through them.
 *
 * ## Three tables, because three things change at three speeds
 *
 *  - `lead_forms`: the form as the team knows it — a name, a public address, a state. It points at the
 *    version that is LIVE.
 *  - `lead_form_versions`: the definition (steps, fields, logic, consent, thank-you, style). A version
 *    that has been published is never edited again; a change is a new version. That is what lets a
 *    lead say, years later, exactly which question the person answered and which consent text they saw.
 *  - `lead_form_submissions`: one row per accepted submission — the version it answered, the consent
 *    state as it stood, the attribution evidence, and the lead it became. Unique on
 *    (tenant, form, idempotency_key): a double click and a retried request are the same submission.
 *
 * The answers themselves live on the LEAD (`leads.form_answers`), in the existing Leads Centre; the
 * submission row keeps the evidence around them, not a second copy of the person's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_forms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->string('name', 160);
            // The public address: /f/{slug}. Unguessable and not derived from the name.
            $table->string('slug', 24)->unique();
            // draft | published | archived
            $table->string('status', 16)->default('draft');
            $table->uuid('published_version_id')->nullable();
            // The person new leads from this form are assigned to, when the team chose one.
            $table->unsignedBigInteger('default_owner_id')->nullable();
            // Days a submission's evidence is kept; null = the workspace default.
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'project_id', 'status']);
        });

        Schema::create('lead_form_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('lead_form_id');
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->string('checksum', 64);
            $table->timestampTz('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['lead_form_id', 'version']);
            $table->foreign('lead_form_id')->references('id')->on('lead_forms')->cascadeOnDelete();
        });

        Schema::create('lead_form_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('lead_form_id');
            $table->uuid('lead_form_version_id');
            $table->uuid('lead_id')->nullable()->index();
            // The browser's own id for this submission; a retry carries the same one.
            $table->string('idempotency_key', 64);
            $table->string('session_id', 64)->nullable();
            // granted | denied — and the text version the person saw. Never inferred.
            $table->string('consent_state', 16);
            $table->string('consent_version', 40)->nullable();
            // utm_*, click ids, landing path, referrer host. Evidence, whitelisted on the way in.
            $table->json('attribution')->nullable();
            $table->timestampTz('submitted_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'lead_form_id', 'idempotency_key'], 'lead_form_submissions_idempotent');
            $table->index(['tenant_id', 'lead_form_id', 'submitted_at']);
            $table->foreign('lead_form_id')->references('id')->on('lead_forms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_form_submissions');
        Schema::dropIfExists('lead_form_versions');
        Schema::dropIfExists('lead_forms');
    }
};
