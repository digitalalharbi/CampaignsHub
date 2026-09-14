<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ANALYTICS-PROVENANCE-001 — a real client's report is made of real rows.
 *
 * ## Why this is asserted on a generated report and not on the aggregator
 *
 * The aggregator's own guard proves `MetricsAggregator` excludes seeded rows from an operational
 * scope. It cannot prove that the REPORT is built through it — and the owner's instruction is
 * explicit that a report containing demo rows is a P0 in its own right, because a document a client
 * keeps is where an invented figure does the most damage and is hardest to withdraw.
 *
 * So this generates a report for a project holding both kinds of row and reads the figures the
 * document actually carries. If some future section reaches around the aggregator to its own query —
 * which is exactly how the creative tables came to have no demo policy while the campaign table did
 * — this fails.
 *
 * ## The demo world still works
 *
 * A project holding ONLY seeded rows is a demo, and its report shows them. Failing that direction
 * would empty every demo report, which is the opposite mistake and just as visible.
 */
final class ReportProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'P', 'slug' => 'pv-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'Client', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale',
            'objective' => 'sales', 'status' => 'active',
        ]);
    }

    public function test_a_real_clients_report_never_contains_seeded_spend(): void
    {
        $this->spend(1_000.0, demo: false);
        $this->spend(9_000.0, demo: true);

        $data = $this->generate();

        $this->assertSame(
            1_000.0,
            (float) ($data['kpis']['spend'] ?? 0),
            'the client’s report added seeded spend to their own',
        );
    }

    /** And the platform breakdown beneath the headline, which is its own query. */
    public function test_the_platform_breakdown_is_real_rows_too(): void
    {
        $this->spend(1_000.0, demo: false);
        $this->spend(9_000.0, demo: true);

        $platforms = $this->generate()['platforms'] ?? [];
        $total = array_sum(array_map(static fn (array $p): float => (float) ($p['spend'] ?? 0), $platforms));

        $this->assertSame(1_000.0, $total, 'the platform rows carried seeded spend the headline excluded');
    }

    /**
     * The demo world keeps working. A project whose rows are ALL seeded is a demo, and emptying its
     * report would be the opposite mistake — just as visible and harder to explain.
     */
    public function test_a_demo_project_still_reports_its_own_figures(): void
    {
        $this->spend(500.0, demo: true);

        $this->assertSame(500.0, (float) ($this->generate()['kpis']['spend'] ?? 0));
    }

    /**
     * `is_demo` is NOT fillable, so it must be forced.
     *
     * The first version of this fixture passed it to `create()`, where it was silently dropped — so
     * the «seeded» row was an ordinary row and the report legitimately summed both. The test reported
     * a P0 that did not exist. The figure was implausible enough to check, which is the only reason
     * it was caught: a fixture that cannot produce the state it names proves nothing about the state.
     */
    private function spend(float $amount, bool $demo): void
    {
        $row = DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'unified_campaign_id' => $this->campaign->getKey(),
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'meta',
            'metric_key' => 'spend',
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'value' => $amount,
            'original_amount' => $amount,
            'original_currency' => 'SAR',
            'project_currency' => 'SAR',
            'exchange_rate' => 1,
        ]);

        $row->forceFill(['is_demo' => $demo])->saveQuietly();
    }

    /** @return array<string, mixed> */
    private function generate(): array
    {
        $report = Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'Monthly',
            'type' => 'performance',
            'status' => 'draft',
            'period_start' => Carbon::today()->subDays(7)->toDateString(),
            'period_end' => Carbon::today()->toDateString(),
            'currency' => 'SAR',
        ]);

        return app(ReportGenerator::class)->generate($report);
    }
}
