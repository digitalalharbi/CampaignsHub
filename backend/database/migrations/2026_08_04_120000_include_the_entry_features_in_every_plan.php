<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PLAN-PAID-001 — a higher plan includes everything the lower one does.
 *
 * Pricing «البداية» gave it two named features, `campaign_tracking` and `reports`, and the console
 * now renders a plan's features as switches. That immediately showed a data error nobody could see
 * while features lived in a paragraph: Growth and Scale — the plans that cost five and fifteen times
 * as much — had neither flag set, so the catalogue said the cheapest plan included campaign tracking
 * and the dearest did not.
 *
 * Nothing about what those plans DO changes. This corrects what the catalogue says about them, which
 * is now the thing the product reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['growth', 'scale'] as $code) {
            $row = DB::table('subscription_plans')->where('code', $code)->first();

            if ($row === null) {
                continue;
            }

            $features = json_decode((string) ($row->features ?? '{}'), true);
            $features = is_array($features) ? $features : [];

            DB::table('subscription_plans')->where('code', $code)->update([
                'features' => json_encode($features + ['campaign_tracking' => true, 'reports' => true]),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would restore a catalogue that says a 1,499 SAR plan has no reports. There is
     * nothing to undo here — the plans always included these; only the record of it was missing.
     */
    public function down(): void {}
};
