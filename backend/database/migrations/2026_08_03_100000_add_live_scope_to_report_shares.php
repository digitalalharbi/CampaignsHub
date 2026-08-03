<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LIVEREP-001 — a shared link can serve LIVE figures instead of a frozen snapshot.
 *
 * Until now a share pointed at `reports.data`: whatever the generator produced at the moment the
 * report was built. That is the right thing for a signed-off monthly document, and the wrong thing
 * for the question a client actually asks between documents — «how is it going today?». They got a
 * number that was true last Tuesday, with nothing on the page saying so.
 *
 * Two columns, and the reason they are on the SHARE rather than the report:
 *
 * - `mode` — `snapshot` (existing behaviour, unchanged and still the default) or `live`.
 * - `scope` — the CEILING a live link may ever read: which project, which campaigns, which platforms,
 *   which metrics, and the earliest/latest date it may reach. The client's own filters are intersected
 *   with this and can only ever narrow it.
 *
 * It belongs to the share because one report may be shared twice with different ceilings — the client
 * sees their two campaigns, the partner agency sees one — and because the ceiling is a property of who
 * was given the link, not of the document. Putting it on the report would make the second share silently
 * widen the first.
 *
 * Existing rows get `snapshot` and a null scope, which is exactly what they have always been. Nothing
 * about a link already in a client's inbox changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->string('mode')->default('snapshot')->after('report_id');
            $table->jsonb('scope')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->dropColumn(['mode', 'scope']);
        });
    }
};
