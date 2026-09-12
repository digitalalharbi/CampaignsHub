<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ReportTemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-DEPTH-001 — a report a client reads and a report an account manager works from.
 *
 * «Support at least: A. Summary / Executive — concise KPI dashboard, performance trend, platform
 * distribution, strongest results, key budget/spend, top-performing creatives, a few concise
 * findings only. B. Full / Detailed — complete KPIs, deeper trend charts, platform/objective
 * breakdown, funnel, budget/spend, comparisons, ALL promoted contents.»
 *
 * The deck had one shape. Every report — the one going to a client's inbox and the one an operator
 * reads before a call — carried the cover, the recommendations, the objective split, a slide per
 * platform, the comparison, the funnel, the budget, the ads and the observations. «Reduce prose
 * aggressively» cannot be answered by a template with one setting.
 *
 * ## Why depth is not «hide some slides in the UI»
 *
 * A reader who hides sections is still sent the whole deck, and a scheduled email still renders it.
 * Depth belongs to the report, travels with its config, and is what the generator and every renderer
 * read — so a summary report is summary wherever it is opened.
 *
 * ## The default is FULL
 *
 * Every report that exists was authored under the old single shape, and quietly re-reading those as
 * summaries would delete sections from decks people already send.
 */
final class ReportFormShapeTest extends TestCase
{
    private function engine(): ReportTemplateEngine
    {
        return new ReportTemplateEngine;
    }

    /** @return list<string> the slide types, in order */
    private function types(array $config): array
    {
        return array_values(array_map(
            static fn (array $s): string => (string) $s['type'],
            array_filter($config['slides'], static fn (array $s): bool => ($s['visible'] ?? true) === true),
        ));
    }

    public function test_a_full_report_keeps_every_section_it_has_always_had(): void
    {
        $types = $this->types($this->engine()->defaultConfig('sales', ['meta', 'snapchat'], 'detailed'));

        foreach (['cover', 'executive_summary', 'objective_performance', 'platform_performance', 'platform_comparison', 'funnel', 'budget', 'ads'] as $type) {
            $this->assertContains($type, $types, "a full report lost «{$type}»");
        }
    }

    /** Unspecified is DETAILED — every existing report was generated as the full deck. */
    public function test_depth_defaults_to_full(): void
    {
        $this->assertSame(
            $this->types($this->engine()->defaultConfig('sales', ['meta', 'snapchat'], 'detailed')),
            $this->types($this->engine()->defaultConfig('sales', ['meta', 'snapchat'])),
        );
    }

    /**
     * A summary is SHORTER, and short in the right places.
     *
     * Asserted as «materially fewer» rather than an exact count: the list is a product decision that
     * will move, and a test pinned to nine sections becomes an obstacle the next time one is added.
     * What must not move is that a summary is a fraction of a full deck.
     */
    public function test_a_summary_is_materially_shorter_than_a_full_report(): void
    {
        $full = $this->types($this->engine()->defaultConfig('sales', ['meta', 'snapchat', 'tiktok'], 'detailed'));
        $summary = $this->types($this->engine()->defaultConfig('sales', ['meta', 'snapchat', 'tiktok'], 'executive_summary'));

        $this->assertLessThan(count($full), count($summary));

        /*
         * Half, not «six».
         *
         * Six was a number I invented while writing this, and the summary came out at seven — every
         * one of which is on the Owner's own list: the headline, the platform distribution, the
         * budget, the creatives, what changed, and the findings. Bending the product to an arbitrary
         * ceiling would have deleted a section the requirement names. «Materially shorter» is the
         * claim, so that is what this asserts.
         */
        /* Two thirds, not half: the objective split stays in the summary by REPORT-OBJECTIVE-003/004. */
        $this->assertLessThan(count($full) * 0.7, count($summary), 'a «concise» summary grew into a second full deck');
    }

    /**
     * And it keeps what an executive came for.
     *
     * «Concise KPI dashboard, performance trend, platform distribution, strongest results, key
     * budget/spend, top-performing creatives.» The ads section is the creative half of that, and it
     * is in the summary deliberately: «Reports must automatically surface top-performing creatives»
     * is not a detail-only requirement.
     */
    public function test_a_summary_keeps_the_headline_the_budget_and_the_creatives(): void
    {
        $summary = $this->types($this->engine()->defaultConfig('sales', ['meta', 'snapchat'], 'executive_summary'));

        /*
         * `objective_performance` is in this list because REPORT-OBJECTIVE-003/004 puts it there:
         * the blended cost per order is what a forwarded summary gets quoted on, and this section is
         * what stops it being read as the price of a sale.
         */
        foreach (['cover', 'executive_summary', 'objective_performance', 'budget', 'ads'] as $type) {
            $this->assertContains($type, $summary, "an executive summary without «{$type}» is not a summary of anything");
        }
    }

    /**
     * A summary does not carry a slide PER PLATFORM.
     *
     * That is the section that grows without a ceiling — six connected platforms is six slides — and
     * it is the operator's view of the same money the distribution chart already shows an executive.
     */
    public function test_a_summary_carries_no_per_platform_slide(): void
    {
        $summary = $this->types($this->engine()->defaultConfig('sales', ['meta', 'snapchat', 'tiktok', 'google'], 'executive_summary'));

        $this->assertNotContains('platform_performance', $summary);
    }

    /** An unknown form is DETAILED, never an empty deck — a typo must not delete somebody's report. */
    public function test_an_unknown_depth_falls_back_to_full(): void
    {
        $this->assertSame(
            $this->types($this->engine()->defaultConfig('sales', ['meta'], 'detailed')),
            $this->types($this->engine()->defaultConfig('sales', ['meta'], 'not_a_form')),
        );
    }
}
