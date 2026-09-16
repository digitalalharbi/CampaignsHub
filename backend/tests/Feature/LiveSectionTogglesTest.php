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
 * LIVE-SECTION-CONTROL-001 — a section switched off leaves the PAYLOAD, not just the page.
 *
 * ## Why this is asserted on the JSON rather than on the rendering
 *
 * `ShareSections` states the rule for the attribution flag: «a section removed from the UI while its
 * data still travels in the JSON is not a permission, it is a CSS rule — and the network tab is one
 * keystroke away». The same sentence is what makes a DISPLAY toggle honest instead of decorative.
 * An operator who unticks «budget» on a client's link is entitled to believe the budget is not in
 * the document, and the only way to be sure is to look at what the document contains.
 *
 * ## Display flags default ON, and that asymmetry is deliberate
 *
 * `attribution` fails closed because publishing it is a disclosure nobody granted. These six are
 * sections a live link has always rendered, so failing them closed would empty every link in
 * existence on the day this shipped. An absent key therefore means ON for them, and OFF is only ever
 * something somebody chose — which the last case here pins, because it is the half that a
 * «fail-closed everywhere» instinct would quietly break.
 */
final class LiveSectionTogglesTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'sec-'.uniqid(), 'status' => 'active']);
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

        $this->report = Report::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'executive', 'status' => 'completed', 'currency' => 'SAR',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(),
            'data' => ['kpis' => ['spend' => 400]],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** @param array<string,bool> $sections */
    private function payloadWith(array $sections): array
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['meta'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);

        if ($sections !== []) {
            $share->settings = ['sections' => $sections];
            $share->save();
        }

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    /**
     * The METRICS an operator chose travel to the payload — the settings audit's last unproven step.
     *
     * `clientKpiKeys` returns the operator's list verbatim when it is non-empty, and
     * `clientKpis.test.ts` proves that half («shows exactly what the operator selected»). What was
     * unproven is the step between: that the selection stored on the share reaches the payload the
     * page reads. A control whose value is saved and never served is the placebo the closure brief
     * rules out, and it would look identical to a working one in the builder.
     *
     * Asserted as an ordered list rather than a set, because the client's page renders THIS list in
     * THIS order — «the metrics the operator chose, in the order they chose to show them».
     */
    public function test_the_metrics_an_operator_chose_reach_the_payload(): void
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['meta'],
                'metrics' => ['spend', 'roas', 'conversions'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);
        unset($share);

        $payload = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');

        $this->assertSame(['spend', 'roas', 'conversions'], $payload['metrics']);
    }

    /**
     * And choosing NOTHING means «all of them», not «none».
     *
     * The empty list is what a link built before metric selection existed carries, and what an
     * operator who skipped the step means. Reading it as an explicit empty selection would render a
     * client a report with no KPI cards at all.
     */
    public function test_choosing_no_metric_is_not_the_same_as_choosing_none(): void
    {
        $this->assertSame([], $this->payloadWith([])['metrics']);
    }

    public function test_a_link_that_chose_nothing_still_carries_every_display_section(): void
    {
        $payload = $this->payloadWith([]);

        $this->assertNotEmpty($payload['platforms'], 'a link built before these toggles existed lost its platforms');
        $this->assertNotEmpty($payload['funnel'], 'and its funnel');
        $this->assertNotNull($payload['deltas'] ?? null, 'and its comparison with the previous period');
    }

    public function test_switching_the_platform_comparison_off_removes_it_from_the_document(): void
    {
        $this->assertNotEmpty($this->payloadWith([])['platforms']);

        $this->assertSame([], $this->payloadWith(['platform_comparison' => false])['platforms']);
    }

    public function test_switching_the_budget_off_removes_it_from_the_document(): void
    {
        $this->assertSame([], $this->payloadWith(['budget' => false])['budget'] ?? []);
    }

    public function test_switching_the_funnel_off_takes_the_store_with_it(): void
    {
        $payload = $this->payloadWith(['funnel_store' => false]);

        $this->assertSame([], $payload['funnel']);
        $this->assertNull($payload['store_funnel']);
    }

    public function test_switching_the_objective_breakdown_off_removes_both_of_its_blocks(): void
    {
        $payload = $this->payloadWith(['objective_breakdown' => false]);

        /* null, not `[]` — an empty LIST would still be truthy on the page and render a block with nothing in it. */
        $this->assertNull($payload['objective_performance']);
        $this->assertNull($payload['objective_leaders']);
    }

    /**
     * The comparison goes; the figures it compared do not.
     *
     * Dropping the totals here would remove the report rather than the comparison — the switch is
     * about movement against the previous period, which is what `deltas` holds.
     */
    public function test_switching_the_previous_comparison_off_keeps_the_figures_themselves(): void
    {
        $payload = $this->payloadWith(['previous_comparison' => false]);

        $this->assertSame([], $payload['deltas']);
        $this->assertNotEmpty($payload['totals'], 'the period\'s own figures went with its comparison');
    }

    /**
     * Attribution is a DISCLOSURE and keeps failing closed — the asymmetry above, pinned.
     *
     * On a WHOLE-PROJECT link, because CLIENT-REPORT-ENTITY-BOUNDARY-001 refuses the attribution
     * section outright on a link narrower than its project: it compares platform claims against the
     * store's entire order ledger, which a partial link may not speak about. The first version of
     * this case used the narrowed share the others use and read the refusal as a broken toggle.
     */
    public function test_attribution_is_still_off_unless_a_link_asks_for_it(): void
    {
        $wide = function (array $sections): array {
            [$share, $raw] = app(ShareService::class)->create($this->report, [
                'mode' => 'live',
                'scope' => [
                    'project_id' => (string) $this->project->getKey(),
                    'campaign_ids' => [],
                    'providers' => ['meta'],
                    'earliest' => now()->subDays(30)->toDateString(),
                    'latest' => now()->toDateString(),
                ],
            ], null);

            $share->settings = ['sections' => $sections];
            $share->save();

            return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
        };

        $this->assertFalse($wide([])['sections']['attribution']);
        $this->assertTrue($wide(['attribution' => true])['sections']['attribution']);
    }
}
