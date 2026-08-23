<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONTENT-STATE-SEMANTICS-001 — the sync knows why a creative has no numbers; nothing recorded it.
 *
 * `AccountMetricsSyncer` asks for creative-level insights inside a bare `catch (Throwable) {}` and
 * writes nothing about the attempt. A provider that does not report creative metrics at all is never
 * asked, and that is not recorded either. So four genuinely different situations arrived at the
 * Content Library as one indistinguishable absence, and every card said «لا توجد بيانات»:
 *
 *   1. the provider has no creative-level reporting  → UNSUPPORTED
 *   2. we asked and the call failed or was throttled → BLOCKED, and it may be stale rather than absent
 *   3. we asked, it succeeded, this creative did not deliver in the window → «لم يعمل خلال هذه الفترة»
 *   4. it delivered → real numbers
 *
 * Only (3) is «no data», and only (4) is a figure. Telling an operator that a creative has no data
 * when the truth is that a request failed is how a pipeline outage looks like an idle campaign.
 *
 * The state is recorded on the run because that is the thing that actually knows: it is per account
 * and per window, which is exactly the scope of the answer. Nullable throughout, so runs written
 * before this migration stay honestly unknown rather than being backfilled into a claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_sync_runs', function (Blueprint $table): void {
            // unsupported | success | failed | skipped. NULL means a run from before this existed.
            $table->string('creative_status', 20)->nullable()->after('mapped_campaign_rows');
            $table->unsignedInteger('creative_rows')->nullable()->after('creative_status');
            // The provider's own words, kept short — a reason an operator can act on.
            $table->string('creative_error', 500)->nullable()->after('creative_rows');
        });
    }

    public function down(): void
    {
        Schema::table('metric_sync_runs', function (Blueprint $table): void {
            $table->dropColumn(['creative_status', 'creative_rows', 'creative_error']);
        });
    }
};
