<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAMPAIGNS-ADOPT-001 — telling «never adopted» apart from «deliberately unlinked».
 *
 * `CAMPAIGNS-VISIBLE-001` made a synced campaign visible by adopting it into a `unified_campaign` —
 * but only on FIRST import, because the obvious condition (`unified_campaign_id IS NULL`) is exactly
 * what `CampaignLinker::unlink()` produces, and adopting on it would silently undo a person's
 * decision on the next sweep.
 *
 * The consequence, live: an account whose campaigns were discovered BEFORE that feature shipped is
 * never new again, so it is never adopted — and its Campaigns page stays empty forever. On the live
 * Snapchat account that is 89 campaigns and 1,056 stored metrics with nothing on screen to attach
 * them to.
 *
 * `unlinked_at` is the record the condition was missing. From now on an unlink says so, and adoption
 * can fire for anything that has never been unlinked, whether it is new or was discovered in July.
 *
 * ## What this migration deliberately does NOT do
 *
 * It does not guess which existing rows were unlinked by hand. `unlink()` nulls `linked_at` as well,
 * so no trace survives, and inventing one would be a fabrication of exactly the kind this codebase
 * refuses. Every existing row therefore starts with `unlinked_at = NULL` and will be adopted once.
 * A person who had unlinked such a row can unlink it again — and from that moment it stays unlinked,
 * which is the guarantee that was missing in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_campaigns', function (Blueprint $table) {
            $table->timestampTz('unlinked_at')->nullable()->after('linked_by');
        });
    }

    public function down(): void
    {
        Schema::table('external_campaigns', function (Blueprint $table) {
            $table->dropColumn('unlinked_at');
        });
    }
};
