<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use Tests\TestCase;

/**
 * The objective→path grouping the dashboard's path control mirrors — UX-DASH-001.
 *
 * `frontend/src/features/campaigns/labels.ts` holds `PATH_OBJECTIVES`, because the path filter is
 * not a server axis: it selects a path's objectives and sends them on the objective filter the
 * metrics API already supports. A drift between the two can only mis-group the CHOICES — the server
 * still filters by exactly the objectives it was handed, so no figure can come out wrong — but a
 * choice filed under the wrong heading is still a lie about what the money is for.
 *
 * So the grouping is written out here rather than derived from the enum. Deriving it would make the
 * test agree with any change automatically, which is the one thing it must not do.
 */
final class CampaignObjectivePathTest extends TestCase
{
    public function test_every_objective_falls_in_the_path_the_dashboard_files_it_under(): void
    {
        $expected = [
            'awareness' => ['awareness', 'reach', 'video_views', 'engagement', 'other'],
            'traffic' => ['traffic', 'landing_page_views', 'store_visits'],
            'conversion' => ['leads', 'app_installs', 'add_to_cart', 'sales', 'conversions', 'purchases'],
        ];

        $actual = [];
        foreach (CampaignObjective::cases() as $objective) {
            $actual[$objective->path()->value][] = $objective->value;
        }

        foreach ($expected as $path => $objectives) {
            sort($objectives);
            $found = $actual[$path] ?? [];
            sort($found);

            $this->assertSame(
                $objectives,
                $found,
                "The «{$path}» path moved. Update PATH_OBJECTIVES in frontend/src/features/campaigns/labels.ts.",
            );
        }

        // And no path exists that the dashboard has never heard of.
        $this->assertSame([], array_diff(array_keys($actual), array_keys($expected)));
    }
}
