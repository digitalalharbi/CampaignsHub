<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FORMS-PDPL-001 — when a submission's surrounding evidence was erased (retention or a data-subject request). Consent proof stays. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_form_submissions', function (Blueprint $table): void {
            $table->timestampTz('evidence_purged_at')->nullable()->after('submitted_at');
            $table->string('purge_reason', 16)->nullable()->after('evidence_purged_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_form_submissions', function (Blueprint $table): void {
            $table->dropColumn(['evidence_purged_at', 'purge_reason']);
        });
    }
};
