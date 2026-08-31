<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Services\DigestPresenter;
use ReflectionClass;
use Tests\TestCase;

/**
 * CREATIVE-RANK-002 — every metric an objective leads with can be named in an email.
 *
 * A metric that reaches the renderer without an entry in the spec gets no label, no unit, and
 * `lower_is_better => false` by omission. That second part is the dangerous one: a rising cost per
 * lead would have been coloured as an improvement, because nobody had said which way was better.
 *
 * The table was written when this product reported conversions and revenue, and did not keep up with
 * the objectives that came after — leads, app, engagement, video.
 */
final class DigestMetricSpecTest extends TestCase
{
    /**
     * The metric table, found by SHAPE rather than by name.
     *
     * `METRICS` is private, and a test that hard-codes the constant's name breaks the day somebody
     * renames it — for a reason that has nothing to do with what this test protects. Looking for the
     * table that describes `spend` keeps the test about the contents.
     *
     * @return array<string, array<string,mixed>>
     */
    private function spec(): array
    {
        foreach ((new ReflectionClass(DigestPresenter::class))->getConstants() as $value) {
            if (is_array($value) && is_array($value['spend'] ?? null) && isset($value['spend']['ar'])) {
                return $value;
            }
        }

        return [];
    }

    /** Every objective's primary KPI, as `metricCatalog`'s layouts name them. */
    private const PRIMARIES = ['cpm', 'ctr', 'engagement_rate', 'cost_per_view', 'cpl', 'roas', 'cpi'];

    public function test_every_objective_primary_can_be_named(): void
    {
        $spec = $this->spec();
        $this->assertNotEmpty($spec, 'the metric spec table was not found');

        foreach (self::PRIMARIES as $key) {
            $this->assertArrayHasKey($key, $spec, "a digest cannot name '{$key}', which some objective leads with");
            $this->assertNotSame('', $spec[$key]['ar'] ?? '');
            $this->assertNotSame('', $spec[$key]['en'] ?? '');
        }
    }

    public function test_every_cost_metric_declares_that_lower_is_better(): void
    {
        // The failure this prevents is silent: an unspecified direction means «up is good», so a
        // worsening cost is shown in the colour of an improvement.
        $spec = $this->spec();

        foreach (['cpm', 'cpc', 'cpa', 'cpl', 'cpe', 'cpi', 'cost_per_view'] as $key) {
            $this->assertArrayHasKey($key, $spec, "'{$key}' is missing entirely");
            $this->assertTrue(
                (bool) ($spec[$key]['lower_is_better'] ?? false),
                "'{$key}' is a cost and does not say lower is better — a rise would read as progress"
            );
        }
    }

    public function test_no_count_or_rate_is_marked_lower_is_better(): void
    {
        $spec = $this->spec();

        foreach (['leads', 'installs', 'engagements', 'clicks', 'conversions', 'purchases', 'roas'] as $key) {
            $this->assertArrayHasKey($key, $spec);
            $this->assertFalse(
                (bool) ($spec[$key]['lower_is_better'] ?? false),
                "'{$key}' is not a cost and must not be ranked as one"
            );
        }
    }
}
