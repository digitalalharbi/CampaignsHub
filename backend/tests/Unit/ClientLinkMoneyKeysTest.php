<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\CreativeVisibility;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CLIENT-REPORT-MONEY-REDACTION-001 — one answer to «what is spend, divided».
 *
 * ## Three lists, three answers, one question
 *
 * A client link that hides spend must not publish a figure that spend can be recovered from. Three
 * places decided independently what those figures are:
 *
 *   `CreativeVisibility::COST_METRICS`  cpc, cpm, cpa, cost_per_view, cost_per_lpv, spend
 *   `ShareService::sanitize()`          spend, cpa, cpc, cpm
 *   `ShareService::sanitizeLive()`      spend, cpa, cpc, cpm, cpl, cpi, cpe
 *
 * So a snapshot link hiding spend still published `cpl` — cost per LEAD, which is spend divided by a
 * lead count printed beside it — and neither ShareService path covered `cost_per_view` or
 * `cost_per_lpv`, which the creative redaction did. `sanitizeLive`'s own docblock makes the argument
 * this test enforces: «an operator who ticked hide spend ticked it about this client, not about one
 * rendering path», and three lists is three rendering paths disagreeing.
 *
 * `cost_per_result` was in none of them, and `ObjectivePerformance` emits it.
 *
 * The union lives on `CreativeVisibility` and every path reads it, so adding a cost-per metric to
 * the product is one edit rather than three that can be made two.
 */
final class ClientLinkMoneyKeysTest extends TestCase
{
    /** Every cost-per figure this product computes. A spend divided is a spend. */
    private const SPEND_DERIVED = [
        'cpc', 'cpm', 'cpa', 'cpl', 'cpi', 'cpe', 'cost_per_view', 'cost_per_lpv', 'cost_per_result',
    ];

    private const REVENUE_DERIVED = ['roas', 'aov'];

    /** The creative-bearing sections, exactly as `ReportCreativeMedia` enumerates them. */
    private const CREATIVE_SECTIONS = ['ads', 'ads_roster', 'worst_creatives', 'top_creatives'];

    #[Test]
    public function the_canonical_cost_list_names_every_cost_per_figure_the_product_computes(): void
    {
        foreach (self::SPEND_DERIVED as $key) {
            $this->assertContains(
                $key,
                CreativeVisibility::COST_METRICS,
                "«{$key}» is spend divided by a number printed beside it, and a link hiding spend would publish it",
            );
        }

        $this->assertContains('spend', CreativeVisibility::COST_METRICS);
    }

    #[Test]
    public function the_canonical_revenue_list_names_every_revenue_derived_figure(): void
    {
        foreach (self::REVENUE_DERIVED as $key) {
            $this->assertContains($key, CreativeVisibility::REVENUE_METRICS, "«{$key}» is revenue divided, and would republish it");
        }

        $this->assertContains('revenue', CreativeVisibility::REVENUE_METRICS);
    }

    /** A row carrying every money figure and both original amounts. */
    private function row(): array
    {
        $row = ['impressions' => 90_000, 'clicks' => 300, 'campaign_name' => 'Sale'];

        foreach ([...self::SPEND_DERIVED, ...self::REVENUE_DERIVED, 'spend', 'revenue'] as $key) {
            $row[$key] = 12.5;
        }

        return $row + [
            'spend_original' => 412.5,
            'revenue_original' => 1980.0,
            'spend_withheld_rows' => 3,
            'revenue_withheld_rows' => 3,
            'money_original_currency' => 'USD',
            'money_original_currencies' => 1,
        ];
    }

    private function share(): ReportShare
    {
        $share = new ReportShare;
        $share->hide_spend = true;
        $share->hide_revenue = true;
        $share->hide_campaign_names = false;

        return $share;
    }

    /** Each section a caller must not be able to read the budget out of. */
    #[Test]
    public function a_snapshot_link_publishes_no_cost_per_figure_and_no_original_amount(): void
    {
        $data = [
            'kpis' => $this->row(),
            'platforms' => [$this->row()],
            'campaigns' => [$this->row()],
            'top_creatives' => [$this->row()],
            'timeseries' => [$this->row()],
            'budget' => [$this->row()],
        ];

        $out = app(ShareService::class)->sanitize($data, $this->share());

        $this->assertRedacted($out['kpis'], 'kpis');
        foreach (['platforms', 'campaigns', 'top_creatives', 'timeseries', 'budget'] as $section) {
            $this->assertRedacted($out[$section][0], $section);
        }
    }

    #[Test]
    public function a_live_link_publishes_no_cost_per_figure_and_no_original_amount(): void
    {
        $payload = [
            'totals' => $this->row(),
            'deltas' => $this->row(),
            'timeseries' => [$this->row()],
            'platforms' => [$this->row()],
            'campaigns' => [$this->row()],
            'ad_sets' => [$this->row()],
            'budget' => [$this->row()],
        ];

        $out = app(ShareService::class)->sanitizeLive($payload, $this->share());

        $this->assertRedacted($out['totals'], 'totals');
        $this->assertRedacted($out['deltas'], 'deltas');
        foreach (['timeseries', 'platforms', 'campaigns', 'ad_sets', 'budget'] as $section) {
            $this->assertRedacted($out[$section][0], $section);
        }
    }

    /** What the client is still entitled to must survive — a redaction that empties the report is not one. */
    #[Test]
    public function the_figures_the_link_does_not_hide_are_untouched(): void
    {
        $out = app(ShareService::class)->sanitizeLive(['totals' => $this->row()], $this->share());

        $this->assertSame(90_000, $out['totals']['impressions'], 'impressions are not money and were removed');
        $this->assertSame(300, $out['totals']['clicks'], 'clicks are not money and were removed');
    }

    /**
     * Every creative-bearing section, not the one the list happened to name.
     *
     * The sanitizers enumerate their sections on purpose — `sanitizeLive`'s own comment says «a
     * section added to the payload and not to this list is a section that ignores the link's hide
     * flags». That is exactly what happened: `ReportCreativeMedia` walks five creative sections
     * (`ads`, `ads_roster`, `worst_creatives`, `top_creatives` and `ads_groups[].ads`) and the
     * snapshot sanitizer named one of them while the live sanitizer named none. An operator who hid
     * spend found it again on the ads gallery — the most-read part of a client report.
     *
     * This asserts the sections by the SAME list the media resolver uses, so a sixth one cannot be
     * added to the payload and silently skipped here.
     */
    #[Test]
    public function every_creative_section_is_redacted_on_a_snapshot_link(): void
    {
        $data = [];
        foreach (self::CREATIVE_SECTIONS as $section) {
            $data[$section] = [$this->row()];
        }
        $data['ads_groups'] = [['ads' => [$this->row()]]];

        $out = app(ShareService::class)->sanitize($data, $this->share());

        foreach (self::CREATIVE_SECTIONS as $section) {
            $this->assertRedacted($out[$section][0], $section);
        }
        $this->assertRedacted($out['ads_groups'][0]['ads'][0], 'ads_groups[].ads');
    }

    #[Test]
    public function every_creative_section_is_redacted_on_a_live_link(): void
    {
        $payload = [];
        foreach (self::CREATIVE_SECTIONS as $section) {
            $payload[$section] = [$this->row()];
        }
        $payload['ads_groups'] = [['ads' => [$this->row()]]];

        $out = app(ShareService::class)->sanitizeLive($payload, $this->share());

        foreach (self::CREATIVE_SECTIONS as $section) {
            $this->assertRedacted($out[$section][0], $section);
        }
        $this->assertRedacted($out['ads_groups'][0]['ads'][0], 'ads_groups[].ads');
    }

    private function assertRedacted(array $row, string $where): void
    {
        foreach ([...self::SPEND_DERIVED, ...self::REVENUE_DERIVED, 'spend', 'revenue'] as $key) {
            $this->assertNull($row[$key] ?? null, "«{$where}» publishes «{$key}» on a link that hides the money it divides");
        }

        foreach (['spend_original', 'revenue_original', 'spend_withheld_rows', 'revenue_withheld_rows', 'money_original_currency', 'money_original_currencies'] as $key) {
            $this->assertNull($row[$key] ?? null, "«{$where}» publishes «{$key}» — the withheld amount itself");
        }
    }
}
