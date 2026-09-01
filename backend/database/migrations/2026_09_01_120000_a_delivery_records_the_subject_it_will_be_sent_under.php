<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-TITLE-METADATA-001 — the subject is decided when the delivery is recorded, not when it is
 * sent.
 *
 * `report_deliveries` is an honest ledger of what will be sent to whom, and it has never carried the
 * one line the recipient actually reads first. With no mail provider on this install every row sits
 * at `awaiting_provider_credentials`, so the subject would otherwise be chosen months later by
 * whatever code finally sends it — and chosen from a report whose name may have changed in the
 * meantime.
 *
 * Recorded here, the subject is the document's own name at the moment the delivery was scheduled,
 * which is what the recipient is entitled to and what an operator reading the ledger can check.
 * Nullable: rows written before this were scheduled without one, and inventing a subject for them
 * retrospectively would be a claim about an email nobody composed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_deliveries', function (Blueprint $table): void {
            $table->string('subject', 300)->nullable()->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('report_deliveries', function (Blueprint $table): void {
            $table->dropColumn('subject');
        });
    }
};
