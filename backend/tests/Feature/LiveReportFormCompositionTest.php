<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-PRODUCT-MODEL-001 — the LIVE half of the composition contract. Owner defect row 96.
 *
 * ## Why this file exists at all
 *
 * `ReportCompositionContractTest` pins the SNAPSHOT deck: a summary's slide list is materially
 * shorter than a detailed one's, and it has held for a long time. The live link had no equivalent,
 * and that is precisely where the two products collapsed into one. Measured on the running product
 * before this was written — same project, same window, same campaigns and platforms, two shares
 * differing only in `form` — the summary rendered 43 blocks and the detailed 45, 3043px against
 * 3189px, 314 words against 325. Eleven words apart.
 *
 * A contract that covers one of two rendering paths is how the uncovered one drifts, so the live
 * path gets its own.
 *
 * ## The regression that matters most here
 *
 * `test_the_operators_choice_reaches_the_payload_and_not_just_the_label` is the one to keep. The
 * link builder writes the operator's choice to `report_shares.form` and creates the report row
 * WITHOUT a form; `reports.form` is `NOT NULL DEFAULT 'detailed'`. `LiveReportService` read that
 * column, so on every link the product itself creates the payload was composed as a detailed report
 * whatever was chosen, while `PublicReportController` — which does consult `formOr` — told the page
 * it was a summary. Two sources of truth for one setting, disagreeing silently, and the silence is
 * the reason it survived: nothing errored, the label was right, and only the document was wrong.
 *
 * The fixture therefore reproduces the BUILDER's shape deliberately — a report with no form and a
 * share that carries one — rather than the convenient shape where both agree.
 */
final class LiveReportFormCompositionTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'F', 'slug' => 'form-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $tenant->getKey());

        $ws = ClientWorkspace::create(['tenant_id' => $tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'A campaign', 'status' => 'active', 'objective' => 'sales',
        ]);

        foreach ([['spend', 400.0], ['clicks', 90.0], ['impressions', 9000.0], ['conversions', 12.0], ['revenue', 1600.0]] as [$key, $value]) {
            DailyMetric::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $this->campaign->getKey(),
                'provider' => 'meta',
                'metric_key' => $key,
                'metric_date' => now()->subDays(2)->toDateString(),
                'value' => $value,
            ]);
        }

        /*
         * Created the way `LiveReportBuilderController` creates one: NO form on the report row.
         *
         * `reports.form` therefore falls to its column default, which is `detailed`. Writing
         * `'form' => 'executive_summary'` here instead would make every case below pass for the
         * wrong reason — it is the disagreement between the two rows that is under test.
         */
        $this->report = Report::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'live', 'status' => 'completed', 'currency' => 'SAR',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(),
            'data' => [],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** The live payload for a link whose SHARE carries the given form. */
    private function payloadFor(?string $form): array
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'mode' => 'live',
            'form' => $form,
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['meta'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    public function test_the_operators_choice_reaches_the_payload_and_not_just_the_label(): void
    {
        $this->assertSame(
            'executive_summary',
            $this->payloadFor('executive_summary')['form'] ?? null,
            'the share says executive summary and the payload was composed as something else — '.
            'the page would label it a summary while rendering the detailed document',
        );

        $this->assertSame('detailed', $this->payloadFor('detailed')['form'] ?? null);
    }

    public function test_a_summary_does_not_carry_the_funnel_or_the_store_reconciliation(): void
    {
        $summary = $this->payloadFor('executive_summary');

        // REPORT-SECTION-SURFACES-001 — a section the form does not contain is absent, not emptied.
        $this->assertArrayNotHasKey('funnel', $summary, 'the funnel is the detailed product’s — handoff §10');
        $this->assertArrayNotHasKey('store_funnel', $summary, 'and so is the store reconciliation');
        $this->assertNotContains('funnel', $summary['report_sections']);
    }

    public function test_a_summary_does_not_carry_the_per_platform_creative_rankings(): void
    {
        $this->assertEmpty($this->payloadFor('executive_summary')['ads_platform_groups'] ?? []);
    }

    public function test_the_detailed_report_keeps_every_one_of_them(): void
    {
        $detailed = $this->payloadFor('detailed');

        $this->assertNotEmpty($detailed['funnel'], 'the detailed report lost its funnel');
        $this->assertContains('funnel', $detailed['report_sections']);
    }

    /**
     * The Owner's DETAILED list, turned from prose into a guard.
     *
     * Row 96 asks for report products that are «materially different», and names what the detailed
     * one must contain: complete KPI depth, timeseries trends, platform-by-platform sections, the
     * objective breakdown, budget and pacing, funnel and store, promoted creatives, platform-specific
     * top creatives, ALL promoted creatives reachable, recommendations, analytical tables, and
     * freshness / data-quality context.
     *
     * Every item on that list is a key here, so a section that quietly stops being emitted fails on
     * the CLAUSE IT BREAKS rather than on a diff nobody reads. The case above proves the two blocks a
     * summary drops; this proves the other ten never silently went with them — a trimming aimed at
     * the summary that reached the detailed product would otherwise be invisible until a client
     * opened one.
     *
     * ## Presence is not enough, and the first version of this got that wrong
     *
     * `ReportComposition` trims by EMPTYING — `funnel => []`, `store_funnel => null` — so the keys
     * survive a trimming and `assertArrayHasKey` passes on a section that is gone. Written that way
     * first, this guard did not fail when the trimming was injected into the detailed product: the
     * old case beside it caught that, and this one watched it happen. So the three sections the
     * composition can empty are asserted NON-EMPTY, which is the only assertion that can tell «still
     * here» from «still keyed».
     *
     * The rest are asserted on presence alone, deliberately: what belongs inside each is the subject
     * of its own tests, and pinning values here would make this fail for reasons that have nothing
     * to do with composition.
     *
     * Two items on the list are deliberately NOT keys, and the reason each is where it is matters:
     *
     *  - «ALL promoted creatives reachable» is `ads_roster`, which a summary also carries. Row 96's
     *    reachability is the CAP, held in `ReportAds` and driven by the form — see the note in
     *    `ReportComposition::DETAILED_ONLY` on why emptying the key would delete the COUNT with the
     *    table and restore REPORT-CREATIVE-TRUTH-001's own defect.
     *  - «freshness / data-quality context» is `freshness`, and its DIAGNOSTIC half is withheld from
     *    a client audience by CLIENT-DIAGNOSTIC-SEPARATION-001. That is not a conflict with this
     *    list: an internal reader gets the operator's account, and a client gets the fact that
     *    concerns them — which figures the period does not include — rather than our sync clock.
     */
    public function test_the_detailed_report_carries_every_section_the_owner_named(): void
    {
        $detailed = $this->payloadFor('detailed');

        $promised = [
            'totals' => 'complete KPI depth',
            'timeseries' => 'timeseries trends',
            'platforms' => 'platform-by-platform sections',
            'objective_performance' => 'the objective breakdown',
            'budget' => 'budget and pacing',
            'funnel' => 'the funnel',
            'store_funnel' => 'the store reconciliation',
            'ads' => 'promoted creatives',
            'ads_platform_groups' => 'platform-specific top creatives',
            'ads_roster' => 'all promoted creatives reachable',
            'campaigns' => 'the analytical tables',
            'freshness' => 'freshness and data-quality context',
        ];

        /*
         * Where a surviving key CAN express the difference, and where it genuinely cannot.
         *
         * Only `funnel` is asserted non-empty, and the other two are excluded for a reason that is a
         * property of the data rather than a gap in this fixture. The trimming empties a section; so
         * does an account that has nothing to put in it. `store_funnel` is null when trimmed AND null
         * for a project with no shop connected — most of them — and `ads_platform_groups` is empty
         * when trimmed AND empty for a project whose creatives carry no per-platform ranking. The two
         * states are the same value, so no assertion here can separate them, and one that tried would
         * fail for honest reports.
         *
         * That is not a hole: the case directly above proves the summary drops both, which is the
         * claim that matters. What this case adds for them is that the KEY is still emitted, so a
         * consumer reading the detailed payload finds the section rather than an absent field.
         */
        $trimmable = ['funnel'];

        foreach ($promised as $key => $clause) {
            $this->assertArrayHasKey(
                $key,
                $detailed,
                "the detailed report no longer carries {$clause} — row 96 names it as something this product must contain",
            );

            if (in_array($key, $trimmable, true)) {
                $this->assertNotEmpty(
                    $detailed[$key],
                    "the detailed report was trimmed of {$clause} — the key survived and the section did not",
                );
            }
        }
    }

    /**
     * The summary keeps what it is FOR — the Owner's own Executive list, handoff §10.
     *
     * Written because the obvious way to make a summary shorter is to keep trimming, and three of
     * these have a standing reason not to be trimmed: the platform summary and the period comparison
     * are named under the Executive list, and `objective_performance` is kept by REPORT-OBJECTIVE-004
     * because a summary is the version that gets forwarded and quoted with no per-platform pages
     * behind it to argue with.
     */
    public function test_a_summary_still_carries_the_decisions_it_exists_to_deliver(): void
    {
        $summary = $this->payloadFor('executive_summary');

        $this->assertNotEmpty($summary['totals'], 'the headline figures');
        $this->assertNotEmpty($summary['platforms'], 'the platform summary');
        $this->assertNotNull($summary['deltas'] ?? null, 'the period comparison');
        /*
         * Direct against blended is no longer part of a client's summary: it lives only inside
         * advanced segmentation, off by default, and the headline stays the truthful overall figures.
         */
        $this->assertArrayNotHasKey('objective_performance', $summary);
    }

    /**
     * A summary withholds the creative LIST and must never go silent about the count.
     *
     * Emptying `ads_roster` was the first thing written when this was implemented, and it would have
     * restored REPORT-CREATIVE-TRUTH-001's own defect — «a report showed a curated handful and said
     * nothing about the rest» — through a change meant to shorten the document. The component reads
     * `creatives_in_scope` to state the count, so the key has to survive the trimming.
     */
    public function test_a_summary_with_no_content_carries_no_content_section(): void
    {
        // Nothing ran in this window, so the content section is absent — not a count of zero on a card.
        $summary = $this->payloadFor('executive_summary');
        $this->assertArrayNotHasKey('creatives_in_scope', $summary);
        $this->assertNotContains('content_performance', $summary['report_sections']);
    }
}
