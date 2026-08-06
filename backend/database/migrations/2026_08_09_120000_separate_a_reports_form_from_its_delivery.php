<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a report IS, kept apart from how it is delivered (REPORT-LINKS-13).
 *
 * Three different questions had been sharing two columns:
 *
 *   - `type` — what the report is ABOUT (`campaign`, `platform_comparison`, `weekly`, `project`).
 *   - `mode` — whether a shared link recomputes or serves a snapshot.
 *   - `audience` — who may read it, which is a redaction rule, not a shape.
 *
 * «Executive summary versus full detail» is a fourth question and had been smuggled into the first
 * two: `type = 'executive'` sat in a list beside `campaign` and `weekly`, and `audience =
 * 'executive'` was doing double duty as a slide filter. So a monthly campaign report could not be
 * issued as a summary without pretending to be a different KIND of report, and an executive report
 * shared by link came out in full detail anyway, because the public reader always applied the plain
 * client filter.
 *
 * `form` answers only that question, which makes the combinations the contract asks for expressible:
 * a summary and a detailed report of the SAME project, each live or snapshot.
 *
 * The backfill reads both old signals. `detailed` is the default because it is what every existing
 * report has been serving — a report that silently became a summary on upgrade would drop pages its
 * readers have been receiving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('form', 20)->default('detailed')->after('type');
        });

        DB::table('reports')
            ->where('type', 'executive')
            ->orWhere('audience', 'executive')
            ->update(['form' => 'executive_summary']);
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('form');
        });
    }
};
