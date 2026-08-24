<?php

declare(strict_types=1);

use App\Domains\Campaigns\Actions\ReclassifyCampaignObjectives;
use Illuminate\Database\Migrations\Migration;

/**
 * OBJECTIVE-NORMALIZATION-004 — run the repair again, now that the map knows the words.
 *
 * The first repair ran against a map that had `WEBSITE_CONVERSIONS` and not `WEB_CONVERSION`, which
 * is what Snapchat actually sends. 71 campaigns could not be classified and were written to `other`.
 *
 * A separate migration rather than an edit to the first: the first has already run in production and
 * Laravel will not run it twice, so a change to its file would be a change nobody executes. This is
 * also the honest record — «the mapping was wrong, here is when it was corrected» is two events, and
 * the migrations table is where the product keeps that history.
 *
 * The action is unchanged in what it refuses: a `manual` objective is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = app(ReclassifyCampaignObjectives::class)->execute();

        if ($result['examined'] === 0) {
            return;
        }

        echo sprintf(
            "OBJECTIVE-NORMALIZATION-004: %d campaign(s) examined, %d reclassified, %d still unclassified.\n",
            $result['examined'],
            $result['reclassified'],
            $result['unclassified'],
        );
    }
};
