<?php

declare(strict_types=1);

use App\Domains\Campaigns\Actions\ReclassifyCampaignObjectives;
use Illuminate\Database\Migrations\Migration;

/**
 * OBJECTIVE-NORMALIZATION-002 — run the repair once, on deploy.
 *
 * The work lives in {@see ReclassifyCampaignObjectives} rather than in this file, and that is not a
 * style preference. The last backfill this repository wrote inline turned out not to be idempotent,
 * and the only reason anybody found out was that extracting it made it testable — a second pass had
 * been writing `NULLIF(NULL, 0)` over preserved money. A migration nobody can run twice on purpose is
 * a migration nobody can prove is safe.
 *
 * There is no `down()`. The reverse of «this column now holds a canonical value» is «put a platform's
 * raw string back into a column that may not hold one», which is the defect, and
 * `objective_platform_value` already keeps the platform's word for anyone who needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = app(ReclassifyCampaignObjectives::class)->execute();

        if ($result['examined'] === 0) {
            return;
        }

        // Printed rather than silent: this touches the column that decides whether a campaign's spend
        // may reach a cost per order, and a deploy log that says nothing about it is a deploy nobody
        // can audit afterwards.
        echo sprintf(
            "OBJECTIVE-NORMALIZATION-002: %d campaign(s) examined, %d reclassified, %d left unclassified.\n",
            $result['examined'],
            $result['reclassified'],
            $result['unclassified'],
        );
    }
};
