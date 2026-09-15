<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
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
 * HEADLINE-SCOPE-001 — which verdict a scope is entitled to, and when it is entitled to none.
 *
 * `objectiveFamiliesInScope()` decides what the analytical surfaces may headline: a scope holding
 * one family gets that family's own row — completion rate for a video buy — and a scope holding two
 * gets the mixed row, the metrics true of any campaign whatever it was bought for.
 *
 * That is the mechanism standing between the product and a generic «Results» that sums purchases,
 * leads and installs into one number. It had no test, so the rule was enforced by a function nothing
 * asked about.
 *
 * ## Why a scope's OWN rows decide, and not the campaigns table
 *
 * The families are read from the campaigns that reported in the window, through the join the
 * breakdown already uses. A project's campaign list is not the same set: a lead campaign that ran
 * last quarter and reported nothing this month must not pull the whole page onto the mixed row, and
 * a window is exactly the thing that decides whether it did.
 */
final class ObjectiveFamiliesInScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'F', 'slug' => 'fs-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'Scope', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
    }

    public function test_one_family_in_the_window_is_reported_as_one(): void
    {
        $this->spendOn('sales', 1);

        $this->assertSame(['sales'], $this->families());
    }

    /** Two families is the mixed case, and both are named rather than one winning. */
    public function test_two_families_are_both_reported(): void
    {
        $this->spendOn('sales', 1);
        $this->spendOn('leads', 2);

        $families = $this->families();
        sort($families);

        $this->assertSame(['leads', 'sales'], $families);
    }

    /**
     * A campaign that reported NOTHING in the window is not in the scope.
     *
     * This is the half a campaigns-table reading would get wrong: the project holds a lead campaign,
     * so «the families here» would be two — and the page would drop from the sales verdict to the
     * mixed row because of a campaign that spent nothing this month.
     */
    public function test_a_campaign_silent_in_the_window_does_not_widen_the_scope(): void
    {
        $this->spendOn('sales', 1);
        $this->spendOn('leads', 90);   // outside the seven-day window asked for below

        $this->assertSame(['sales'], $this->families());
    }

    /** An objective the taxonomy cannot name is «unknown», not absent and not guessed at. */
    public function test_an_unrecognised_objective_becomes_the_unknown_family(): void
    {
        $this->spendOn('something_the_taxonomy_has_never_heard_of', 1);

        $this->assertSame(['unknown'], $this->families());
    }

    /** @return list<string> */
    private function families(): array
    {
        return app(MetricsAggregator::class)
            ->forProjects([(string) $this->project->getKey()])
            ->objectiveFamiliesInScope(Carbon::today()->subDays(7), Carbon::today());
    }

    private function spendOn(string $objective, int $daysAgo): void
    {
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'client_workspace_id' => $this->project->client_workspace_id,
            'name' => $objective.' campaign',
            'objective' => $objective,
            'status' => 'active',
        ]);

        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'unified_campaign_id' => $campaign->getKey(),
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'meta',
            'metric_key' => 'spend',
            'metric_date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'value' => 100,
            'original_amount' => 100,
            'original_currency' => 'SAR',
            'project_currency' => 'SAR',
            'exchange_rate' => 1,
        ]);
    }
}
