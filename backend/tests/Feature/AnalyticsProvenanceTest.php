<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ANALYTICS-PROVENANCE-001 — «Demo» must be a fact about the rows, not a constant.
 *
 * The dashboard, campaigns and analytics all rendered `<DemoBadge />` unconditionally, so a project
 * syncing real Snapchat spend was labelled «بيانات تجريبية · Demo» beside its own money. A badge that
 * is always on says nothing — and this one says something false, because it is the product's promise
 * that a figure is NOT a customer's real spend.
 */
final class AnalyticsProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Proj', 'status' => 'active',
        ]);
    }

    public function test_a_project_with_only_real_rows_is_live(): void
    {
        $this->metric(isDemo: false);

        $this->assertSame('live', $this->provenance()['source']);
    }

    public function test_a_project_with_only_seeded_rows_is_demo(): void
    {
        $this->metric(isDemo: true);

        $this->assertSame('demo', $this->provenance()['source']);
    }

    /**
     * Both is a real state and is REPORTED, not resolved.
     *
     * Choosing one label would hide demo rows inside a live total — the leak this exists to expose.
     */
    public function test_a_project_holding_both_is_mixed_rather_than_silently_one(): void
    {
        $this->metric(isDemo: false);
        $this->metric(isDemo: true, key: 'clicks');

        $p = $this->provenance();

        $this->assertSame('mixed', $p['source']);
        $this->assertSame(1, $p['live_rows']);
        $this->assertSame(1, $p['demo_rows']);
    }

    public function test_a_project_with_no_rows_claims_nothing(): void
    {
        $this->assertSame('none', $this->provenance()['source']);
    }

    /** Another project's demo rows must not make THIS project read as demo. */
    public function test_demo_rows_in_another_project_do_not_leak(): void
    {
        $this->metric(isDemo: false);

        $otherWs = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C2', 'slug' => 'c2-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $other = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $otherWs->id, 'name' => 'Other', 'status' => 'active',
        ]);
        $this->metric(isDemo: true, projectId: $other->id);

        $p = $this->provenance();

        $this->assertSame('live', $p['source'], "Another project's demo rows leaked into this one.");
        $this->assertSame(0, $p['demo_rows']);
    }

    /** Scoped exactly as `provenance()` is, so the two are answering about the same rows. */
    private function aggregator(): MetricsAggregator
    {
        app(ProjectContext::class)->setProjectId($this->project->id);

        return app(MetricsAggregator::class)->forProjects([$this->project->id]);
    }

    /** @return array{source:string,live_rows:int,demo_rows:int} */
    private function provenance(): array
    {
        app(ProjectContext::class)->setProjectId($this->project->id);
        $out = app(MetricsAggregator::class)
            ->forProjects([$this->project->id])
            ->provenance(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));
        app(ProjectContext::class)->forget();

        return $out;
    }

    /**
     * `forceFill`, not `create`, and the reason matters.
     *
     * `is_demo` is deliberately absent from `DailyMetric::$fillable` — a demo flag that could be
     * mass-assigned is one an untrusted payload could clear, disguising seeded rows as real. So
     * `create(['is_demo' => true])` silently DROPS it and writes a live row.
     *
     * The first version of this fixture did exactly that and the demo cases failed while the code was
     * correct. Production is unaffected: the demo seeder writes through `UpsertDailyMetrics`, the same
     * path the real ingest uses, which sets the column explicitly.
     */
    /*
     * ── DEMO-LIVE-AGGREGATION-ISOLATION-001 ──────────────────────────────────────────────────────
     *
     * The badge above these figures could already say «mixed». The figures themselves were still
     * being summed together, so a live project holding seeded rows reported invented money inside a
     * real total and labelled it honestly. Naming a leak is not closing it.
     */

    public function test_a_live_project_total_excludes_demo_rows_in_the_same_project(): void
    {
        $this->metric(isDemo: false);   // 100 real
        $this->metric(isDemo: false);   // 100 real
        $this->metric(isDemo: true);    // 100 seeded — must not be added to the customer's spend

        $totals = $this->aggregator()->totals(
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertEqualsWithDelta(
            200.0,
            (float) $totals['spend'],
            0.01,
            'The seeded row was added to a real total — invented money a customer would be shown.',
        );
    }

    /** A project that has only demo rows is a demo project, and demo rows are exactly what to show. */
    public function test_a_demo_only_project_still_reports_its_demo_figures(): void
    {
        $this->metric(isDemo: true);
        $this->metric(isDemo: true);

        $totals = $this->aggregator()->totals(
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertEqualsWithDelta(
            200.0,
            (float) $totals['spend'],
            0.01,
            'Excluding demo rows everywhere would blank the demo tenant, which is not the goal.',
        );
    }

    /**
     * Provenance still reports BOTH counts after the policy exists.
     *
     * If provenance were filtered by the same policy it could only ever answer «live», which is the
     * one answer it exists to be able to doubt — and the contamination would become invisible again.
     */
    public function test_provenance_still_sees_the_demo_rows_the_totals_now_exclude(): void
    {
        $this->metric(isDemo: false);
        $this->metric(isDemo: true);

        $provenance = $this->aggregator()->provenance(
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertSame('mixed', $provenance['source']);
        $this->assertSame(1, $provenance['live_rows']);
        $this->assertSame(1, $provenance['demo_rows'], 'The excluded rows must still be countable.');
    }

    /**
     * The policy is decided by the SCOPE, not the window.
     *
     * A live row in May and a demo row in June: asking about June alone must still be an operational
     * question. If the window decided, the same KPI would change meaning as somebody dragged the date
     * picker, with nothing on screen to say why.
     */
    public function test_the_window_cannot_turn_a_live_project_into_a_demo_one(): void
    {
        $this->metricOn('2026-05-01', isDemo: false);
        $this->metricOn('2026-06-01', isDemo: true);

        $totals = $this->aggregator()->totals(
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertEqualsWithDelta(
            0.0,
            (float) ($totals['spend'] ?? 0),
            0.01,
            'June holds only a seeded row; a live project must report nothing rather than demo money.',
        );
    }

    /** A live row in one project must not make ANOTHER project operational. */
    public function test_the_policy_is_per_scope_not_global(): void
    {
        $other = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'name' => 'Demo only',
            'status' => 'active',
        ]);

        $this->metric(isDemo: false);                              // live, in this project
        $this->metric(isDemo: true, projectId: $other->id);        // demo, in the other

        $totals = app(MetricsAggregator::class)
            ->acrossProjects()
            ->forProjects([(string) $other->id])
            ->totals(Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'));

        $this->assertEqualsWithDelta(
            100.0,
            (float) $totals['spend'],
            0.01,
            'The other project holds only demo rows, so its own figures are what it should report.',
        );
    }

    private function metricOn(string $date, bool $isDemo): void
    {
        $row = new DailyMetric;
        $row->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'snapchat',
            'metric_key' => 'spend',
            'metric_date' => $date,
            'value' => 100,
            'source_type' => $isDemo ? 'demo' : 'api',
            'is_demo' => $isDemo,
        ])->save();
    }

    private function metric(bool $isDemo, string $key = 'spend', ?string $projectId = null): void
    {
        $row = new DailyMetric;
        $row->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $projectId ?? $this->project->id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'snapchat',
            'metric_key' => $key,
            'metric_date' => '2026-06-01',
            'value' => 100,
            'source_type' => $isDemo ? 'demo' : 'api',
            'is_demo' => $isDemo,
        ])->save();
    }
}
