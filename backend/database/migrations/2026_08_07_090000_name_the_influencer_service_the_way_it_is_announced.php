<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INFL-SOON-001 — one name for one service, on the marketing page and in the intake form.
 *
 * The row was seeded as «حملة مؤثرين أو UGC» while the homepage announces «علاقات المؤثرين وUGC».
 * With the service withheld the discrepancy was invisible, because the row was never shown anywhere;
 * now that the intake form names it as coming soon, a visitor would meet two spellings of the same
 * thing and reasonably wonder whether they are two offerings.
 *
 * A LABEL change only. The `key` is untouched, so every existing request of this type keeps pointing
 * at the same row — which is the whole reason the row was preserved rather than deleted when the
 * sub-system was switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('request_types')->where('key', 'influencer_ugc')->update([
            'name_ar' => 'علاقات المؤثرين وUGC',
            'name_en' => 'Influencer relations & UGC',
        ]);
    }

    public function down(): void
    {
        DB::table('request_types')->where('key', 'influencer_ugc')->update([
            'name_ar' => 'حملة مؤثرين أو UGC',
            'name_en' => 'Influencer / UGC campaign',
        ]);
    }
};
