<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use Tests\TestCase;

/**
 * The objective→path grouping `CampaignObjective::path()` classifies a creative by — UX-DASH-001.
 *
 * This no longer guards a filter. ANALYTICS-OBJECTIVE-SYSTEM-001 removed the «المسار التسويقي»
 * control and the `PATH_OBJECTIVES` map behind it, because a path and an objective were one decision
 * offered twice — `CanonicalObjective` is the single grouping the product now offers a reader.
 *
 * `path()` itself stays, and so does this test: every creative row carries a `path`, shown on the
 * creative pages and used to compare a creative against its peers. A creative filed under the wrong
 * heading is still a lie about what the money is for.
 *
 * The grouping is written out here rather than derived from the enum. Deriving it would make the test
 * agree with any change automatically, which is the one thing it must not do.
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
                "The «{$path}» path moved, so every creative filed under it is now filed wrongly.",
            );
        }

        // And no path exists that the creative pages have never heard of.
        $this->assertSame([], array_diff(array_keys($actual), array_keys($expected)));
    }
}
