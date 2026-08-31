<?php

declare(strict_types=1);

use App\Domains\Campaigns\Actions\StampHistoricalUnlinks;
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
 * ## Existing rows are recovered from EVIDENCE, not left to chance
 *
 * Leaving every existing row unstamped would have the first sweep re-adopt each one — including the
 * ones a person detached on purpose. That is a silent reversal of real decisions, and it is not
 * acceptable.
 *
 * `StampHistoricalUnlinks` recovers them from the audit trail, and the reason that trail is PROOF
 * rather than a hint is checkable rather than assumed: `CampaignLinker::unlink()` is the only path
 * that clears the link, its one route has always written `campaign.external_unlinked` (since
 * `280f333`, the commit that introduced external campaigns at all), `audit_logs` was created three
 * days before `create_campaigns_tables`, and nothing prunes it — `integrations:prune-raw` retains
 * provider payloads, not audits.
 *
 * So every external campaign that has ever existed lived its whole life under unlink auditing. A row
 * with no entry was never unlinked; a row with one carries the timestamp of the most recent decision,
 * because a campaign can be linked and unlinked more than once and the last decision is the one that
 * stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_campaigns', function (Blueprint $table) {
            $table->timestampTz('unlinked_at')->nullable()->after('linked_by');
        });

        $stamped = (new StampHistoricalUnlinks)->execute();

        // Printed so the deploy log carries the evidence: how many decisions were recovered, and
        // therefore how many rows this migration protected from being silently re-adopted.
        echo "  CAMPAIGNS-ADOPT-001: {$stamped} historically unlinked campaign(s) recovered from the audit trail.".PHP_EOL;
    }

    public function down(): void
    {
        Schema::table('external_campaigns', function (Blueprint $table) {
            $table->dropColumn('unlinked_at');
        });
    }
};
