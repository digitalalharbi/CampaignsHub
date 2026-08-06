<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `conversions` and `revenue` could not say «the platform did not report this» (§15.15).
 *
 * The video columns were made nullable when the creative became a unit of analysis, for exactly this
 * reason — but these two were left `NOT NULL DEFAULT 0`, and they are the pair that decides whether a
 * creative is judged on sales at all. The consequence is not theoretical; it was visible the first
 * time two creatives were compared side by side:
 *
 *   An awareness image, bought for reach, was stored with `conversions = 0` and `revenue = 0` because
 *   the column allowed nothing else. Those are MEASURED values, so the aggregation reported them, and
 *   the comparison showed «ROAS 0.00×» beside the sales video's «5.55×». Read plainly, that says the
 *   awareness ad spent twenty thousand riyals and returned nothing — a verdict on a creative that was
 *   never bought to return anything, and precisely the awareness/sales mixing the contract forbids.
 *
 * Nullable, they aggregate to NULL, `CreativeMetrics::$reported` marks them absent, and every surface
 * says «غير مُرسَل» instead. A creative that genuinely sold nothing still stores a real `0` and still
 * reads as zero — the point is that the two cases are now expressible apart.
 *
 * The DEFAULT goes with the NOT NULL deliberately: leaving `DEFAULT 0` in place would quietly restore
 * the old behaviour for every writer that omits the column, which is most of them.
 *
 * Existing rows are left exactly as they are. A stored `0` was recorded as a measurement, and this
 * migration has no way to tell which of them meant «none» and which meant «not reported» — inventing
 * that distinction retroactively would be worse than the ambiguity it replaces. New syncs and re-seeds
 * write the honest value; historical rows keep their zeros and their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw DDL rather than `->change()`: Doctrine's diff rewrites the column type as well, and on
        // `numeric(24,6)` that is a table rewrite this does not need.
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN conversions DROP NOT NULL');
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN conversions DROP DEFAULT');
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN revenue DROP NOT NULL');
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN revenue DROP DEFAULT');
    }

    public function down(): void
    {
        // Reversible, and it has to fill the nulls first — the constraint cannot be restored over the
        // very rows this migration exists to allow. They become `0`, which is the meaning the column
        // had before; the information that they were «not reported» is lost, which is the honest cost
        // of going back to a column that cannot express it.
        DB::statement('UPDATE creative_daily_metrics SET conversions = 0 WHERE conversions IS NULL');
        DB::statement('UPDATE creative_daily_metrics SET revenue = 0 WHERE revenue IS NULL');

        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN conversions SET DEFAULT 0');
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN conversions SET NOT NULL');
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN revenue SET DEFAULT 0');
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN revenue SET NOT NULL');
    }
};
