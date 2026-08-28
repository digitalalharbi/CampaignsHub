<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\CanonicalObjective;
use Tests\TestCase;

/**
 * ANALYTICS-OBJECTIVE-SYSTEM-001 — the filter control and the enum must not drift apart.
 *
 * `frontend/src/features/campaigns/canonicalObjectives.ts` mirrors `CanonicalObjective` so the filter
 * can render without a round trip. This is the same arrangement `CampaignObjectivePathTest` guarded
 * for `PATH_OBJECTIVES`, and it replaces it.
 *
 * The expected grouping is written out HERE explicitly rather than derived from the enum. Deriving it
 * would make this test agree with any change automatically, which is the one thing it must not do —
 * its entire purpose is to notice when someone moves an objective on one side only.
 *
 * A drift here is worse than the path drift this replaces. The path control could only mis-group the
 * CHOICES, because the server was always handed a list of raw objectives and filtered by exactly
 * those. This mirror carries that raw list, so a drift sends the WRONG OBJECTIVES to the server and
 * the figures themselves come out wrong.
 */
final class CanonicalObjectiveParityTest extends TestCase
{
    /** Raw objectives per canonical objective, as the frontend must also spell them. */
    private const EXPECTED = [
        'awareness_engagement' => ['awareness', 'reach', 'video_views', 'engagement'],
        'traffic' => ['traffic', 'landing_page_views'],
        'leads' => ['leads'],
        'app_promotion' => ['app_installs'],
        'sales' => ['sales', 'conversions', 'add_to_cart', 'purchases'],
    ];

    public function test_the_enum_expands_to_exactly_the_raw_objectives_the_filter_sends(): void
    {
        foreach (self::EXPECTED as $key => $expected) {
            $objective = CanonicalObjective::from($key);
            $actual = $objective->rawObjectives();

            sort($expected);
            sort($actual);

            $this->assertSame(
                $expected,
                $actual,
                "«{$key}» moved. Update CANONICAL_OBJECTIVE_RAW in frontend/src/features/campaigns/canonicalObjectives.ts to match.",
            );
        }
    }

    public function test_the_frontend_mirror_lists_the_same_five_objectives(): void
    {
        $mirror = base_path('../frontend/src/features/campaigns/canonicalObjectives.ts');

        if (! is_file($mirror)) {
            $this->markTestSkipped('Frontend mirror not present in this checkout.');
        }

        $source = (string) file_get_contents($mirror);

        foreach (array_keys(self::EXPECTED) as $key) {
            $this->assertStringContainsString(
                "'{$key}'",
                $source,
                "The frontend mirror is missing «{$key}».",
            );
        }

        // A sixth canonical objective would be a competing taxonomy, which is what this replaced.
        $this->assertCount(5, CanonicalObjective::selectable());
    }

    /**
     * No raw objective is claimed twice, and anything unclaimed is explicitly Unknown.
     *
     * An overlap would double-count a campaign across two filters. An objective that is neither
     * claimed nor Unknown would be unreachable from the only control the product offers — it exists,
     * it spent money, and no filter could show it.
     *
     * `other` and `store_visits` ARE unclaimed, deliberately: `CampaignObjective::family()` files both
     * under Unknown because a footfall objective reports neither online revenue nor leads, and filing
     * it under Sales would headline figures that are structurally absent. Campaigns carrying them stay
     * reachable through «الكل», which sends an empty objective list and narrows nothing. That is the
     * correct home for an unclassified objective — a sixth visible choice would be the competing
     * taxonomy this requirement exists to remove.
     */
    public function test_no_raw_objective_is_orphaned_or_claimed_twice(): void
    {
        $seen = [];

        foreach (CanonicalObjective::selectable() as $objective) {
            foreach ($objective->rawObjectives() as $raw) {
                $this->assertArrayNotHasKey($raw, $seen, "«{$raw}» is claimed by two canonical objectives.");
                $seen[$raw] = $objective->value;
            }
        }

        foreach (CampaignObjective::cases() as $raw) {
            if (array_key_exists($raw->value, $seen)) {
                continue;
            }

            $this->assertSame(
                CanonicalObjective::Unknown,
                $raw->family()->canonical(),
                "«{$raw->value}» is in no canonical objective and is not Unknown either, so nothing can reach it.",
            );
        }
    }
}
