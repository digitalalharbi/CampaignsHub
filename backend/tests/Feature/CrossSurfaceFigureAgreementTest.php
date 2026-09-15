<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One project, one period, three surfaces, one set of figures.
 *
 * ## Why this is asserted across endpoints rather than inside the aggregator
 *
 * Every surface here already has its own tests, and they all pass — which is precisely the condition
 * under which two surfaces can disagree. A per-surface test asks «does this endpoint compute what
 * its own author intended», and drift is what happens when two authors intended slightly different
 * things: one counts a day the other excludes, one filters demo rows and the other forgot, one reads
 * `spend` and the other sums a breakdown that is missing a provider.
 *
 * The owner's complaint is the one this shape of defect produces. The dashboard says one number, the
 * report a client keeps says another, and both are internally consistent. So the question asked here
 * is not what any single surface computes but whether they AGREE, which no single-surface test can
 * ask and no amount of passing single-surface tests implies.
 *
 * ## The fixture is built so disagreement is visible
 *
 * Two providers, several days, and a seeded row alongside the real ones — a project with one
 * provider and one day cannot expose a surface that drops a provider, misreads a boundary, or
 * forgets the demo policy, and would pass while every one of those defects was present.
 */
final class CrossSurfaceFigureAgreementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    /** The real (non-demo) figures the fixture puts in, which every surface must report back. */
    private const SPEND = 4_250.0;

    private const IMPRESSIONS = 310_000.0;

    private const CLICKS = 7_400.0;

    protected function setUp(): void
    {
        parent::setUp();

        // The capability catalogue must exist before a role can be granted anything from it:
        // `givePermissionTo` on a permission that is not seeded grants nothing and fails as a 403.
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'X', 'slug' => 'xs-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'Agreement', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale',
            'objective' => 'sales', 'status' => 'active',
        ]);

        // Two providers across three days, split unevenly so a dropped provider or a dropped day
        // changes the total rather than cancelling out.
        $this->row('meta', 'spend', 1_000.0, 1);
        $this->row('meta', 'spend', 1_500.0, 2);
        $this->row('snapchat', 'spend', 1_750.0, 3);
        $this->row('meta', 'impressions', 120_000.0, 1);
        $this->row('meta', 'impressions', 90_000.0, 2);
        $this->row('snapchat', 'impressions', 100_000.0, 3);
        $this->row('meta', 'clicks', 3_000.0, 1);
        $this->row('meta', 'clicks', 2_400.0, 2);
        $this->row('snapchat', 'clicks', 2_000.0, 3);

        // And a seeded row inside the same window. A surface that forgets the demo policy reports a
        // number no other surface does.
        $this->row('meta', 'spend', 99_000.0, 2, demo: true);
    }

    /**
     * The dashboard's figures are read from `metrics/summary`, not from `overview`.
     *
     * `overview` was the obvious-looking endpoint and carries no money at all: it answers with team,
     * bindings, tasks and notifications, and an explicit `not_available_yet` for what it does not
     * cover. Asserting agreement against it would have compared the report to a payload with no
     * figures in it and passed for the wrong reason.
     */
    public function test_the_analytics_summary_reports_the_figures_that_are_there(): void
    {
        $summary = $this->surface('metrics/summary');

        $this->assertSame(self::SPEND, $this->figure($summary, 'spend'), 'the analytics summary spend');
        $this->assertSame(self::IMPRESSIONS, $this->figure($summary, 'impressions'), 'the analytics summary impressions');
        $this->assertSame(self::CLICKS, $this->figure($summary, 'clicks'), 'the analytics summary clicks');
    }

    /**
     * And the document a client keeps carries the same figures as the screen an operator reads.
     *
     * This is the pair that matters most: a disagreement here is the one that leaves the building.
     */
    public function test_the_generated_report_agrees_with_the_analytics_summary(): void
    {
        $summary = $this->surface('metrics/summary');
        $report = $this->generate();

        foreach (['spend' => self::SPEND, 'impressions' => self::IMPRESSIONS, 'clicks' => self::CLICKS] as $key => $expected) {
            $onScreen = $this->figure($summary, $key);
            $inDocument = (float) ($report['kpis'][$key] ?? -1);

            $this->assertSame($expected, $onScreen, "the analytics summary {$key}");
            $this->assertSame(
                $onScreen,
                $inDocument,
                "the report a client keeps says {$key} = {$inDocument} where the screen says {$onScreen}",
            );
        }
    }

    /** The report's own platform breakdown adds up to its own headline — one document, two queries. */
    public function test_the_report_breakdown_adds_up_to_the_report_headline(): void
    {
        $report = $this->generate();
        $rows = array_sum(array_map(static fn (array $p): float => (float) ($p['spend'] ?? 0), $report['platforms'] ?? []));

        $this->assertSame((float) ($report['kpis']['spend'] ?? -1), $rows, 'the report’s platform rows and its headline');
    }

    // ── fixture ───────────────────────────────────────────────────────────────────────────────

    private function row(string $provider, string $key, float $value, int $daysAgo, bool $demo = false): void
    {
        $row = DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'unified_campaign_id' => $this->campaign->getKey(),
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'provider' => $provider,
            'metric_key' => $key,
            'metric_date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'value' => $value,
            'original_amount' => $key === 'spend' ? $value : null,
            'original_currency' => 'SAR',
            'project_currency' => 'SAR',
            'exchange_rate' => 1,
        ]);

        // `is_demo` is not fillable — it must be forced, or the «seeded» row is an ordinary one and
        // the fixture cannot produce the state it names.
        $row->forceFill(['is_demo' => $demo])->saveQuietly();
    }

    /** @return array<string, mixed> */
    private function surface(string $path): array
    {
        $from = Carbon::today()->subDays(7)->toDateString();
        $to = Carbon::today()->toDateString();

        $response = $this->actingAs($this->reader(), 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/{$path}?from={$from}&to={$to}")
            ->assertOk();

        return $response->json('data') ?? [];
    }

    /**
     * Read one figure out of a surface's payload.
     *
     * The surfaces do not share a response shape — the analytics summary nests the period's figures
     * under `current` beside its previous-period comparison, a report names them under `kpis` — and
     * normalising here is honest about that. What is NOT allowed
     * is treating a missing key as zero: an absent figure and a figure of zero are different claims,
     * and collapsing them is how a surface that reports nothing passes a test about agreement.
     */
    private function figure(array $payload, string $key): float
    {
        foreach ([$payload['current'] ?? [], $payload['totals'] ?? [], $payload['kpis'] ?? [], $payload] as $scope) {
            if (is_array($scope) && array_key_exists($key, $scope) && is_numeric($scope[$key])) {
                return (float) $scope[$key];
            }
        }

        $this->fail("no surface figure for «{$key}» — the payload carries ".implode(', ', array_keys($payload)));
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

    private function reader(): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Reader', 'slug' => 'reader-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'projects.view.all', 'campaigns.view', 'dashboard.view', 'analytics.view');

        $user = User::create([
            'name' => 'R', 'email' => 'r-'.uniqid().'@agree.local',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }
}
