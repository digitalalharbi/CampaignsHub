<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * FX-ENGINE-001 — the platform SAR→USD pegged row (migrated from the workspace default) applies to every date.
 *
 * Tests written before that row existed use SAR as a stand-in for "a currency with only the dated rates I insert"
 * (round figures like 4.0 per dollar, or no rate at all to prove withholding). They exercise the dated-rate engine,
 * not the peg, so they start without the migrated row. The peg itself is covered by FxEngineTest.
 */
trait WithoutMigratedSarPeg
{
    protected function setUpWithoutMigratedSarPeg(): void
    {
        DB::table('currency_rates')->where('note', 'like', 'migrated: workspace default%')->delete();
    }
}
