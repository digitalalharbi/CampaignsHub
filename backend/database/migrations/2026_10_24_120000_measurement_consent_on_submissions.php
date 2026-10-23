<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FORMS-DISPATCH-001 — the second consent, kept as its own fact.
 *
 * `consent_state` on a submission is the consent to be CONTACTED. Reporting a person's hashed identifiers to a
 * measurement or advertising platform is a different purpose and a disclosure to a third party, so it has its own
 * state: `granted`, `denied`, or `not_asked` (the form's version did not ask). Only `granted` lets a conversion leave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_form_submissions', function (Blueprint $table): void {
            $table->string('measurement_consent', 10)->default('not_asked')->after('consent_version');
        });
    }

    public function down(): void
    {
        Schema::table('lead_form_submissions', function (Blueprint $table): void {
            $table->dropColumn('measurement_consent');
        });
    }
};
